<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent by the marketing outbox (never queued itself). */
class BackInStockNotification extends Notification
{
    use Queueable;

    /**
     * @param array{
     *     book_id: int,
     *     book_title: string,
     *     book_slug?: string,
     *     variant_id: int,
     *     format: string,
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
        $bookTitle = $this->payload['book_title'] ?? 'Book';
        $format = ucfirst($this->payload['format'] ?? 'book');
        $customerName = $this->payload['customer_name'] ?? 'Reader';
        // The storefront's book page is /books/{id}.
        $shopUrl = config('shop.url');
        $path = '/books/'.(int) ($this->payload['book_id'] ?? 0);
        $url = $shopUrl ? rtrim($shopUrl, '/').$path : url($path);

        return (new MailMessage)
            ->subject(__('mail.back_in_stock_subject', ['title' => $bookTitle]))
            ->greeting(__('mail.back_in_stock_greeting', ['name' => $customerName]))
            ->line(__('mail.back_in_stock_intro', ['title' => $bookTitle, 'format' => $format]))
            ->action(__('mail.back_in_stock_action'), $url)
            ->line(__('mail.back_in_stock_outro'));
    }
}
