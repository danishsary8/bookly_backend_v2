<?php

namespace App\Console\Commands;

use App\Models\EbookFile;
use App\Services\Ebooks\EbookPages;
use Illuminate\Console\Command;

class RenderEbookPages extends Command
{
    protected $signature = 'ebooks:render {--seconds=25 : Keep rendering batches for about this long}';

    protected $description = 'Render the next batch of pages of the oldest ebook still processing';

    public function handle(EbookPages $pages): int
    {
        // On the free host this runs from web requests every few minutes, so use up to ~25 seconds per run
        // (several batches) instead of one batch; each batch is small enough to never hit memory or time limits.
        $until = microtime(true) + (float) $this->option('seconds');
        $file = null;
        $count = 0;
        do {
            $file = EbookFile::where('status', EbookFile::STATUS_PROCESSING)->orderBy('updated_at')->first();
            $rendered = $file ? $pages->renderNext($file) : 0;
            $count += $rendered;
        } while ($file !== null && $rendered > 0 && microtime(true) < $until);
        $this->info($file ? "Ebook {$file->id}: rendered {$count} page(s), {$file->pages_rendered}/{$file->page_count}." : ($count > 0 ? "Rendered {$count} page(s)." : 'No ebook to render.'));

        return self::SUCCESS;
    }
}
