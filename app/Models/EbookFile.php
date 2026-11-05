<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A book's ebook: the master PDF and its pages rendered to images, on the private ebook disk. */
class EbookFile extends Model
{
    public const STATUS_PROCESSING = 'processing';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    /** Default free preview: this share of the pages (rounded up, at least one). */
    public const PREVIEW_SHARE = 0.10;

    protected $fillable = ['book_id', 'path', 'size_bytes', 'page_count', 'pages_rendered', 'preview_pages', 'status', 'error', 'uploaded_by_staff_id'];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'page_count' => 'integer', 'pages_rendered' => 'integer', 'preview_pages' => 'integer'];
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    /** How many pages anyone may read for free. */
    public function previewLimit(): int
    {
        $limit = $this->preview_pages ?? (int) ceil($this->page_count * self::PREVIEW_SHARE);

        return max(1, min($limit, max(1, $this->page_count)));
    }

    public function masterPath(): string
    {
        return $this->path.'/book.pdf';
    }

    public function pagePath(int $page): string
    {
        return $this->path.'/pages/'.$page.'.jpg';
    }
}
