<?php

namespace App\Http\Controllers\Api\V1\Reader;

use App\Http\Controllers\Controller;
use App\Http\Resources\BookResource;
use App\Models\Book;
use App\Models\EbookFile;
use App\Services\Ebooks\EbookDevices;
use App\Services\Ebooks\EbookLibrary;
use App\Services\Ebooks\EbookPages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * The signed-in customer's ebooks: library, reading pages (watermarked images, never the file) and progress.
 * See the ebooks design (2026-11-06).
 */
class ReaderController extends Controller
{
    public function __construct(
        private readonly EbookLibrary $library,
        private readonly EbookPages $pages,
        private readonly EbookDevices $devices,
    ) {}

    /** Account → My ebooks: owned ebooks, most recently read first. */
    public function library(Request $request): JsonResponse
    {
        $customer = $request->user();
        $ids = $this->library->ownedBookIds($customer);
        $files = EbookFile::whereIn('book_id', $ids)->get()->keyBy('book_id');
        $progress = DB::table('ebook_progress')->where('customer_id', $customer->id)->get()->keyBy('book_id');
        $books = Book::query()->forCards()->whereIn('books.id', $ids)->get();

        $rows = $books->map(function (Book $book) use ($files, $progress, $request) {
            $file = $files->get($book->id);
            $read = $progress->get($book->id);

            return [
                'book' => (new BookResource($book))->toArray($request),
                'status' => $file?->status ?? 'missing',
                'page_count' => $file?->page_count ?? 0,
                'last_page' => $read ? (int) $read->page : null,
                'last_read_at' => $read?->updated_at,
            ];
        })->sortByDesc(fn ($row) => $row['last_read_at'] ?? '')->values();

        return response()->json(['data' => $rows]);
    }

    /** What the reader needs before showing pages. Works for non-owners too (owned=false → preview only). */
    public function show(Request $request, int $book): JsonResponse
    {
        $model = Book::query()->visible()->findOrFail($book);
        $file = EbookFile::where('book_id', $model->id)->firstOrFail();
        $owned = $this->library->owningOrder($request->user(), $model->id) !== null;
        $page = DB::table('ebook_progress')->where('customer_id', $request->user()->id)->where('book_id', $model->id)->value('page');

        return response()->json(['data' => [
            'book_id' => $model->id,
            'title' => $model->title,
            'status' => $file->status,
            'page_count' => $file->page_count,
            'preview_pages' => $file->previewLimit(),
            'owned' => $owned,
            'last_page' => $page !== null ? (int) $page : null,
        ]]);
    }

    public function page(Request $request, int $book, int $page): Response
    {
        $customer = $request->user();
        $file = EbookFile::where('book_id', $book)->firstOrFail();
        $order = $this->library->owningOrder($customer, $book);
        abort_if($order === null, 403, __('messages.ebook_not_owned'));
        abort_unless($file->isReady(), 409, __('messages.ebook_not_ready'));
        abort_if($page < 1 || $page > $file->page_count, 404);
        $this->devices->resolve($customer, $request->header('X-Reader-Device'), $request->userAgent());

        $mark = 'Bookly · '.($customer->email ?: 'customer #'.$customer->id).' · '.$order->order_number;

        return self::image($this->pages->watermarked($file, $page, $mark));
    }

    public function progress(Request $request, int $book): JsonResponse
    {
        $data = $request->validate(['page' => ['required', 'integer', 'min:1', 'max:65535']]);
        $customer = $request->user();
        $file = EbookFile::where('book_id', $book)->firstOrFail();
        abort_if($this->library->owningOrder($customer, $book) === null, 403, __('messages.ebook_not_owned'));
        $page = min((int) $data['page'], max(1, $file->page_count));
        DB::table('ebook_progress')->upsert(
            [['customer_id' => $customer->id, 'book_id' => $book, 'page' => $page, 'updated_at' => now()]],
            ['customer_id', 'book_id'], ['page', 'updated_at'],
        );

        return response()->json(['data' => ['page' => $page]]);
    }

    /** A page image that must not be cached or saved by anything between us and the reader. */
    public static function image(string $jpeg): Response
    {
        return response($jpeg, 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
        ]);
    }
}
