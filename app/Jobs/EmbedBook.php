<?php

namespace App\Jobs;

use App\Models\Book;
use App\Services\AI\EmbeddingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class EmbedBook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $bookId,
        public bool $force = false,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(EmbeddingService $service): void
    {
        if (! config('shop.ai_similar_enabled')) {
            return;
        }

        $book = Book::find($this->bookId);
        if (! $book) {
            return;
        }

        $service->embedBook($book, $this->force);
    }
}
