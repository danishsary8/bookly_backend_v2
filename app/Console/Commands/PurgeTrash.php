<?php

namespace App\Console\Commands;

use App\Models\Author;
use App\Models\Book;
use App\Models\Category;
use App\Models\Publisher;
use App\Models\Series;
use Illuminate\Console\Command;

class PurgeTrash extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'trash:purge {--days=30 : Number of days retention before permanent deletion}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Permanently purge soft-deleted catalog items that exceeded the trash retention window';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoff = now()->subDays($days);

        $models = [
            'books' => Book::onlyTrashed()->where('deleted_at', '<', $cutoff),
            'authors' => Author::onlyTrashed()->where('deleted_at', '<', $cutoff),
            'categories' => Category::onlyTrashed()->where('deleted_at', '<', $cutoff),
            'publishers' => Publisher::onlyTrashed()->where('deleted_at', '<', $cutoff),
            'series' => Series::onlyTrashed()->where('deleted_at', '<', $cutoff),
        ];

        $totalPurged = 0;

        foreach ($models as $type => $query) {
            $count = 0;
            foreach ($query->get() as $item) {
                $item->forceDelete();
                $count++;
            }
            if ($count > 0) {
                $this->info("Purged {$count} {$type} older than {$days} days.");
                $totalPurged += $count;
            }
        }

        $this->info("Trash purge complete. {$totalPurged} item(s) permanently removed.");

        return self::SUCCESS;
    }
}
