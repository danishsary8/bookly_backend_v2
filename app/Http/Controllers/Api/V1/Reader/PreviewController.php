<?php

namespace App\Http\Controllers\Api\V1\Reader;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\EbookFile;
use App\Services\Ebooks\EbookPages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/** "Look inside": the first pages of an ebook for anyone, no account needed (default 10%). */
class PreviewController extends Controller
{
    public function show(int $book): JsonResponse
    {
        $file = $this->readyFile($book);

        return response()->json(['data' => [
            'book_id' => $book,
            'title' => $file->book->title,
            'page_count' => $file->page_count,
            'preview_pages' => $file->previewLimit(),
        ]]);
    }

    public function page(int $book, int $page, EbookPages $pages): Response
    {
        $file = $this->readyFile($book);
        abort_if($page < 1 || $page > $file->previewLimit(), 404);

        return ReaderController::image($pages->watermarked($file, $page, 'Bookly · free preview'));
    }

    private function readyFile(int $book): EbookFile
    {
        Book::query()->visible()->findOrFail($book);

        return EbookFile::with('book')->where('book_id', $book)->where('status', EbookFile::STATUS_READY)->firstOrFail();
    }
}
