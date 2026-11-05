<?php

namespace App\Http\Controllers\Api\V1\Staff\Catalog;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\Customer;
use App\Models\EbookFile;
use App\Services\Admin\AuditLogger;
use App\Services\Ebooks\EbookDevices;
use App\Services\Ebooks\EbookPages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Staff: a book's ebook PDF (upload, preview length, remove) and resetting a customer's reading devices. */
class EbookFileController extends Controller
{
    /** Largest PDF accepted, in KB (php.ini allows a little more). */
    public const MAX_KB = 50 * 1024;

    public function __construct(private readonly AuditLogger $audit, private readonly EbookPages $pages) {}

    public function show(Book $book): JsonResponse
    {
        $file = EbookFile::where('book_id', $book->id)->first();

        return response()->json(['data' => $file ? self::present($file) : null]);
    }

    public function store(Request $request, Book $book): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:'.self::MAX_KB]]);
        $upload = $request->file('file');
        $handle = fopen($upload->getRealPath(), 'rb');
        $magic = $handle ? fread($handle, 5) : '';
        if ($handle) {
            fclose($handle);
        }
        if ($magic !== '%PDF-' || strtolower($upload->getClientOriginalExtension()) !== 'pdf') {
            throw ValidationException::withMessages(['file' => 'Upload the ebook as a PDF file.']);
        }
        try {
            $pageCount = $this->pages->pageCount($upload->getRealPath());
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        $disk = EbookPages::disk();
        $old = EbookFile::where('book_id', $book->id)->first();
        // A new random folder each time: the old pages stay readable until the new ones are ready to replace them.
        $path = 'ebooks/'.$book->id.'-'.Str::lower(Str::random(20));
        $disk->putFileAs($path, $upload, 'book.pdf');

        $file = EbookFile::updateOrCreate(['book_id' => $book->id], [
            'path' => $path,
            'size_bytes' => (int) $upload->getSize(),
            'page_count' => $pageCount,
            'pages_rendered' => 0,
            'status' => EbookFile::STATUS_PROCESSING,
            'error' => null,
            'uploaded_by_staff_id' => $request->user()->id,
        ]);
        if ($old && $old->path !== $path) {
            $disk->deleteDirectory($old->path);
        }
        $this->audit->custom($request->user(), 'ebook_uploaded', $book, null, ['pages' => $pageCount, 'size_bytes' => $file->size_bytes]);

        return response()->json(['data' => self::present($file)], 201);
    }

    public function update(Request $request, Book $book): JsonResponse
    {
        $file = EbookFile::where('book_id', $book->id)->firstOrFail();
        $data = $request->validate(['preview_pages' => ['present', 'nullable', 'integer', 'min:1', 'max:'.max(1, $file->page_count)]]);
        $file->update(['preview_pages' => $data['preview_pages']]);

        return response()->json(['data' => self::present($file)]);
    }

    public function destroy(Request $request, Book $book): JsonResponse
    {
        $file = EbookFile::where('book_id', $book->id)->firstOrFail();
        EbookPages::disk()->deleteDirectory($file->path);
        $file->delete();
        $this->audit->custom($request->user(), 'ebook_removed', $book);

        return response()->json(null, 204);
    }

    public function resetDevices(Request $request, Customer $customer, EbookDevices $devices): JsonResponse
    {
        $count = $devices->reset($customer);
        $this->audit->custom($request->user(), 'ebook_devices_reset', $customer, null, ['devices' => $count]);

        return response()->json(['data' => ['reset' => $count]]);
    }

    /** @return array<string, mixed> */
    private static function present(EbookFile $file): array
    {
        return [
            'status' => $file->status,
            'page_count' => $file->page_count,
            'pages_rendered' => $file->pages_rendered,
            'preview_pages' => $file->preview_pages,
            'preview_limit' => $file->previewLimit(),
            'size_bytes' => $file->size_bytes,
            'error' => $file->error,
            'updated_at' => $file->updated_at,
        ];
    }
}
