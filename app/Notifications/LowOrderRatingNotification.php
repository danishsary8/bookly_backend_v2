<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class LowOrderRatingNotification extends Notification
{
    public function __construct(private readonly int $orderId, private readonly string $number, private readonly int $stars) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function databaseType(object $notifiable): string
    {
        return 'low_order_rating';
    }

    public function toArray(object $notifiable): array
    {
        return ['order_id' => $this->orderId, 'stars' => $this->stars,
            'message' => __('messages.low_order_rating', ['number' => $this->number, 'stars' => $this->stars])];
    }
}
