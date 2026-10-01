<?php

namespace App\Notifications;

use App\Models\BookVariant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** In-app alert for staff when a sale pushes a format to or below its low-stock threshold. */
class LowStockNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly array $variant)
    {
        $this->afterCommit();
    }

    public static function for(BookVariant $variant): self
    {
        return new self([
            'book_variant_id' => $variant->id,
            'book_id' => $variant->book_id,
            'title' => $variant->book?->title,
            'format' => $variant->format->value,
            'sku' => $variant->sku,
            'stock_quantity' => $variant->stock_quantity,
            'low_stock_threshold' => $variant->low_stock_threshold,
        ]);
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function databaseType(object $notifiable): string
    {
        return 'low_stock';
    }

    public function toArray(object $notifiable): array
    {
        return [
            ...$this->variant,
            'message' => "Low stock: \"{$this->variant['title']}\" ({$this->variant['format']}) has {$this->variant['stock_quantity']} left.",
        ];
    }
}
