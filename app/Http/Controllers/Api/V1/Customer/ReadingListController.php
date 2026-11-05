<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReadingListResource;
use App\Models\Book;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\ReadingList;
use App\Services\Admin\AuditLogger;
use App\Services\Cart\CartService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReadingListController extends Controller
{
    private function load(ReadingList $list): ReadingList
    {
        return $list->load(['customer', 'books' => fn ($q) => $q->forCards()]);
    }

    public function index(Request $request)
    {
        return ReadingListResource::collection(ReadingList::where('customer_id', $request->user()->id)->orderBy('id')->get()->map(fn ($list) => $this->load($list)));
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules());
        $list = DB::transaction(function () use ($request, $data) {
            Customer::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            if (ReadingList::where('customer_id', $request->user()->id)->count() >= 20) {
                throw ValidationException::withMessages(['title' => __('messages.reading_lists_limit')]);
            }
            $list = new ReadingList($data);
            $list->customer_id = $request->user()->id;
            $list->slug = Str::lower((string) Str::ulid());
            $list->save();

            return $list;
        });

        return (new ReadingListResource($this->load($list)))->response()->setStatusCode(201);
    }

    private function owned(Request $request, int $list): ReadingList
    {
        return ReadingList::where('customer_id', $request->user()->id)->lockForUpdate()->findOrFail($list);
    }

    public function update(Request $request, int $list)
    {
        $data = $request->validate($this->rules('sometimes'));
        $model = DB::transaction(function () use ($request, $list, $data) {
            $model = $this->owned($request, $list);
            $model->fill($data);
            if (! $model->is_public) {
                $model->is_staff_pick = false;
            }
            $model->save();

            return $model;
        });

        return new ReadingListResource($this->load($model));
    }

    public function destroy(Request $request, int $list)
    {
        DB::transaction(fn () => $this->owned($request, $list)->delete());

        return response()->noContent();
    }

    public function addBook(Request $request, int $list)
    {
        $data = $request->validate(['book_id' => ['required', 'integer'], 'note' => ['sometimes', 'nullable', 'string', 'max:1000', 'not_regex:/[<>]/'], 'position' => ['sometimes', 'integer', 'min:0', 'max:199']]);
        $data['book_id'] = (int) $data['book_id'];
        $model = DB::transaction(function () use ($request, $list, $data) {
            $model = $this->owned($request, $list);
            Book::visible()->findOrFail($data['book_id']);
            $existing = $model->books()->where('books.id', $data['book_id'])->exists();
            if (! $existing && $model->books()->count() >= 200) {
                throw ValidationException::withMessages(['book_id' => __('messages.reading_list_books_limit')]);
            }
            $pivot = $existing ? [] : ['position' => $model->books()->count()];
            foreach (['note'] as $key) {
                if (array_key_exists($key, $data)) {
                    $pivot[$key] = $data[$key];
                }
            }
            $model->books()->syncWithoutDetaching([$data['book_id'] => $pivot]);
            if (isset($data['position'])) {
                $ids = $model->books()->pluck('books.id')->reject(fn ($id) => $id === $data['book_id'])->values()->all();
                array_splice($ids, min($data['position'], count($ids)), 0, [$data['book_id']]);
                foreach ($ids as $position => $id) {
                    $model->books()->updateExistingPivot($id, ['position' => $position]);
                }
            }

            return $model;
        });

        return new ReadingListResource($this->load($model));
    }

    public function removeBook(Request $request, int $list, int $book)
    {
        $model = DB::transaction(function () use ($request, $list, $book) {
            $model = $this->owned($request, $list);
            $model->books()->detach($book);
            foreach ($model->books()->pluck('books.id') as $position => $id) {
                $model->books()->updateExistingPivot($id, ['position' => $position]);
            }

            return $model;
        });

        return new ReadingListResource($this->load($model));
    }

    public function publicShow(string $slug)
    {
        return new ReadingListResource($this->load(ReadingList::publiclyVisible()->where('slug', $slug)->firstOrFail()));
    }

    public function staffPicks()
    {
        return ReadingListResource::collection(ReadingList::publiclyVisible()->where('is_staff_pick', true)->latest()->limit(6)->get()->map(fn ($list) => $this->load($list)));
    }

    public function staffIndex()
    {
        return ReadingListResource::collection(ReadingList::publiclyVisible()->latest()->limit(100)->get()->map(fn ($list) => $this->load($list)));
    }

    public function addAll(Request $request, string $slug, CartService $carts)
    {
        $list = ReadingList::publiclyVisible()->where('slug', $slug)->firstOrFail();
        $books = $list->books()->forCards()->get();
        $result = DB::transaction(function () use ($request, $carts, $books) {
            $cart = $carts->cartFor($request->user());
            Cart::whereKey($cart->id)->lockForUpdate()->firstOrFail();
            $existing = $cart->items()->with('variant')->get()->pluck('variant.book_id');
            $added = $skipped = 0;
            foreach ($books as $book) {
                if ($existing->contains($book->id)) {
                    $skipped++;

                    continue;
                }
                $variant = $book->activeVariants->filter(fn ($v) => $v->isAvailable())->sortBy([['price_usd', 'asc'], ['id', 'asc']])->first();
                if (! $variant) {
                    $skipped++;

                    continue;
                }
                try {
                    $carts->add($request->user(), $variant->id, 1);
                    $added++;
                } catch (ValidationException) {
                    $skipped++;
                }
            }

            return compact('added', 'skipped');
        });

        return response()->json(['data' => $result]);
    }

    public function staffPick(Request $request, int $list)
    {
        $data = $request->validate(['is_staff_pick' => ['required', 'boolean']]);
        $model = DB::transaction(function () use ($list, $data, $request) {
            $model = ReadingList::publiclyVisible()->lockForUpdate()->findOrFail($list);
            $before = ['is_staff_pick' => $model->is_staff_pick];
            $model->is_staff_pick = $data['is_staff_pick'];
            $model->save();
            app(AuditLogger::class)->custom($request->user(), 'updated', $model, $before, $data);

            return $model;
        });

        return new ReadingListResource($this->load($model));
    }

    private function rules(string $required = 'required'): array
    {
        return ['title' => [$required, 'string', 'max:100', 'not_regex:/[<>]/'], 'description' => ['sometimes', 'nullable', 'string', 'max:2000', 'not_regex:/[<>]/'], 'is_public' => ['sometimes', 'boolean']];
    }
}
