<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent by the marketing outbox (never queued itself). See App\Services\Marketing\PriceDropAlerts. */
class PriceDropNotification extends Notification
{
    use Queueable;

    /**
     * @param array{
     *     book_id: int,
     *     book_title: string,
     *     variant_id: int,
     *     format: string,
     *     old_price_usd: string,
     *     new_price_usd: string,
     *     customer_name?: string
     * } $payload
     */
    public function __construct(public array $payload) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $title = $this->payload['book_title'] ?? 'Book';
        $format = __('mail.format_'.($this->payload['format'] ?? ''));
        if (str_starts_with($format, 'mail.format_')) {
            $format = ucfirst((string) ($this->payload['format'] ?? 'book'));
        }
        $shopUrl = config('shop.url');
        $base = $shopUrl ? rtrim($shopUrl, '/') : url('');
        $old = '$'.($this->payload['old_price_usd'] ?? '');
        $new = '$'.($this->payload['new_price_usd'] ?? '');

        return (new MailMessage)
            ->subject(__('mail.price_drop_subject', ['title' => $title]))
            ->greeting(__('mail.price_drop_greeting', ['name' => $this->payload['customer_name'] ?? 'Reader']))
            ->line(__('mail.price_drop_intro', ['title' => $title, 'format' => $format, 'old' => $old, 'new' => $new]))
            ->action(__('mail.price_drop_action'), $base.'/books/'.(int) ($this->payload['book_id'] ?? 0))
            ->line(__('mail.price_drop_outro', ['url' => $base.'/account/profile']));
    }
}
