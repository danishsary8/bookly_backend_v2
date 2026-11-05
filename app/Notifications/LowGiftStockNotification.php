<?php

namespace App\Notifications;

use App\Models\Gift;
use Illuminate\Notifications\Notification;

class LowGiftStockNotification extends Notification
{
    public function __construct(private readonly Gift $gift) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function databaseType(object $notifiable): string
    {
        return 'low_gift_stock';
    }

    public function toArray(object $notifiable): array
    {
        return ['gift_id' => $this->gift->id, 'stock_quantity' => $this->gift->stock,
            'message' => "Low gift stock: {$this->gift->name_en} has {$this->gift->stock} left."];
    }
}
