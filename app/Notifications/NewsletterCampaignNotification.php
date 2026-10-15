<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Symfony\Component\Mime\Email;

class NewsletterCampaignNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param array{
     *     subject: string,
     *     body: string,
     *     books?: array<int, array{id: int, title: string, price?: string, url: string, cover_url?: string}>,
     *     unsubscribe_token?: string
     * } $payload
     */
    public function __construct(public array $payload) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $subject = $this->payload['subject'] ?? config('app.name', 'Bookly');
        $rawBody = $this->payload['body'] ?? '';

        $mail = (new MailMessage)
            ->subject($subject)
            ->greeting(config('app.name', 'Bookly'));

        // Split body into paragraphs, escape HTML tags, allow simple line breaks
        $paragraphs = preg_split('/\r?\n\r?\n/', trim($rawBody));
        foreach ($paragraphs as $paragraph) {
            $cleaned = trim($paragraph);
            if ($cleaned === '') {
                continue;
            }
            $mail->line($cleaned);
        }

        // Chosen books cards
        $books = $this->payload['books'] ?? [];
        if (! empty($books)) {
            $mail->line('---');
            foreach ($books as $book) {
                $title = $book['title'] ?? 'Book';
                $price = ! empty($book['price']) ? ' — '.$book['price'] : '';
                $url = $book['url'] ?? '';

                $mail->line('**'.$title.'**'.$price);
                if ($url) {
                    $actionText = __('mail.back_in_stock_action') ?: 'View book';
                    $mail->line('['.$actionText.']('.$url.')');
                }
            }
        }

        // Footer with unsubscribe link & RFC 8058 headers
        $unsubToken = $this->payload['unsubscribe_token'] ?? '';
        if ($unsubToken !== '') {
            $shopUrl = config('shop.url') ?: 'http://localhost:5173';
            $unsubWebUrl = rtrim($shopUrl, '/').'/newsletter/unsubscribe?token='.urlencode($unsubToken);
            $oneClickUrl = url('/api/v1/newsletter/unsubscribe/one-click?token='.urlencode($unsubToken));

            $mail->line('---');
            $mail->line(__('mail.newsletter_unsubscribe_notice'));
            $mail->line('['.__('mail.newsletter_unsubscribe_action').']('.$unsubWebUrl.')');

            $mail->withSymfonyMessage(function (Email $message) use ($oneClickUrl) {
                $message->getHeaders()->addTextHeader('List-Unsubscribe', '<'.$oneClickUrl.'>');
                $message->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            });
        }

        return $mail;
    }
}
