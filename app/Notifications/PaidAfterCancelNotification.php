<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** In-app alert for staff: a KHQR payment arrived after its order was cancelled, so the money must be refunded by hand. */
class PaidAfterCancelNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly array $payment)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function databaseType(object $notifiable): string
    {
        return 'paid_after_cancel';
    }

    public function toArray(object $notifiable): array
    {
        return [
            ...$this->payment,
            'message' => "Order {$this->payment['order_number']} was paid by KHQR ({$this->payment['amount']} {$this->payment['currency']}) after it was cancelled. Refund the customer by hand.",
        ];
    }
}
