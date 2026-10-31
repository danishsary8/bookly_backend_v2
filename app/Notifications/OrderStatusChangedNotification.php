<?php

namespace App\Notifications;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Notifications\Concerns\SendsAfterResponse;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells the customer about processing, shipped, delivered and cancelled. The staff note (e.g. tracking number) is included. */
class OrderStatusChangedNotification extends Notification implements ShouldQueue
{
    use Queueable, SendsAfterResponse;

    public function __construct(
        public readonly Order $order,
        public readonly OrderStatus $status,
        public readonly ?string $note = null,
    ) {}

    /** Statuses that produce an email. `paid`/`returned` are internal; the return emails cover `returned`. */
    public static function covers(OrderStatus $status): bool
    {
        return in_array($status, [OrderStatus::Processing, OrderStatus::Shipped, OrderStatus::Delivered, OrderStatus::Cancelled], true);
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $number = $this->order->order_number;

        [$subject, $intro] = match ($this->status) {
            OrderStatus::Processing => [__('mail.order_processing_subject', ['number' => $number]), __('mail.order_processing_intro')],
            OrderStatus::Shipped => [__('mail.order_shipped_subject', ['number' => $number]), __('mail.order_shipped_intro')],
            OrderStatus::Delivered => [__('mail.order_delivered_subject', ['number' => $number]), __('mail.order_delivered_intro')],
            default => [__('mail.order_cancelled_subject', ['number' => $number]), __('mail.order_cancelled_intro')],
        };

        $mail = (new MailMessage)
            ->subject($subject)
            ->greeting(__('mail.hello_greeting', ['name' => $notifiable->name]))
            ->line($intro)
            ->line(__('mail.order_number', ['number' => $number]));

        if (filled($this->note)) {
            $mail->line(__('mail.shop_note', ['note' => $this->note]));
        }

        if ($this->status === OrderStatus::Delivered) {
            $mail->line(__('mail.receipt_delivered', [
                'total' => $this->order->total_amount,
                'subtotal' => $this->order->subtotal,
                'shipping' => $this->order->shipping_fee,
                'discount' => $this->order->discount_amount,
            ]))->line(__('mail.return_prompt'));
        }

        if ($this->status === OrderStatus::Cancelled) {
            $mail->line(__('mail.order_cancelled_footer'));
        }

        $shop = rtrim((string) (config('shop.url') ?: config('app.url')), '/');
        if ($shop !== '') {
            $mail->action(__('mail.view_order'), "{$shop}/account/orders/{$this->order->id}");
        }

        return $mail;
    }
}
