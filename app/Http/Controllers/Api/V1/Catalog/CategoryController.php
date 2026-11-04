<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Models\Book;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class CategoryController extends Controller
{
    /**
     * Categories are a short list, so they are returned unpaginated for menus and filters. `?tree=1` returns the
     * top-level categories with their children nested and `total_books` (the category plus its children, each book
     * counted once); empty categories are left out of the tree so the menu never leads to an empty page.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $all = Category::query()->withCount(['books' => fn ($q) => $q->visible()])
            ->orderBy('sort_order')->orderBy('name')->get();

        if (! $request->boolean('tree')) {
            return CategoryResource::collection($all->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values());
        }

        $visible = Book::query()->visible()->select('books.id');
        $pairs = DB::table('book_categories')->joinSub($visible, 'v', 'v.id', '=', 'book_categories.book_id')
            ->get(['book_categories.book_id', 'book_categories.category_id']);
        $booksIn = $pairs->groupBy('category_id')->map(fn ($rows) => $rows->pluck('book_id')->all());

        $byParent = $all->groupBy(fn (Category $c) => $c->parent_id ?? 0);
        $tree = ($byParent[0] ?? collect())->map(function (Category $top) use ($byParent, $booksIn) {
            $children = ($byParent[$top->id] ?? collect())->filter(fn (Category $c) => $c->books_count > 0)->values();
            $ids = [...($booksIn[$top->id] ?? [])];
            foreach ($children as $child) {
                $child->total_books = $child->books_count;
                array_push($ids, ...($booksIn[$child->id] ?? []));
            }
            $top->total_books = count(array_unique($ids));
            $top->setRelation('children', $children);

            return $top;
        })->filter(fn (Category $c) => $c->total_books > 0)->values();

        return CategoryResource::collection($tree);
    }
}
