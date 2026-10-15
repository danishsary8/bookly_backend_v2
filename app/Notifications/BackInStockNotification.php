<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BackInStockNotification extends Notification implements ShouldQueue
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
        $slug = $this->payload['book_slug'] ?? ($this->payload['book_id'] ?? '');

        $shopUrl = config('shop.url');
        $url = $shopUrl
            ? rtrim($shopUrl, '/').'/books/'.$slug
            : url('/books/'.$slug);

        return (new MailMessage)
            ->subject(__('mail.back_in_stock_subject', ['title' => $bookTitle]))
            ->greeting(__('mail.back_in_stock_greeting', ['name' => $customerName]))
            ->line(__('mail.back_in_stock_intro', ['title' => $bookTitle, 'format' => $format]))
            ->action(__('mail.back_in_stock_action'), $url)
            ->line(__('mail.back_in_stock_outro'));
    }
}
