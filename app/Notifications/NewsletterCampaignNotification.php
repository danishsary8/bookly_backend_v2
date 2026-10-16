<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Symfony\Component\Mime\Email;

/**
 * One newsletter email. Sent by the marketing outbox (never queued itself), which adds the subscriber's unsubscribe token.
 */
class NewsletterCampaignNotification extends Notification
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

        // Paragraphs on blank lines, single line breaks kept; HTML is escaped by the mail template.
        foreach (preg_split('/\r?\n\s*\r?\n/', trim($rawBody)) as $paragraph) {
            $cleaned = self::onlyShopLinks(trim($paragraph));
            if ($cleaned !== '') {
                $mail->line(preg_replace('/\r?\n/', "  \n", $cleaned));
            }
        }

        // Chosen books cards
        $books = $this->payload['books'] ?? [];
        if (! empty($books)) {
            $mail->line('---');
            foreach ($books as $book) {
                $title = str_replace(['[', ']', '*'], ['\\[', '\\]', '\\*'], $book['title'] ?? 'Book');
                $price = ! empty($book['price']) ? ' — '.$book['price'] : '';
                $url = $book['url'] ?? '';

                if (! empty($book['cover_url'])) {
                    $mail->line('![]('.$book['cover_url'].')');
                }
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

    /**
     * Admins write plain text. Markdown images are dropped, and a [text](url) link survives only when it points to the
     * shop; any other becomes its text, so a newsletter can't carry tracking pixels or links to other sites.
     */
    public static function onlyShopLinks(string $text): string
    {
        $shop = rtrim((string) config('shop.url'), '/');
        $text = preg_replace('/!\[[^\]]*\]\([^)]*\)/', '', $text);

        return preg_replace_callback('/\[([^\]]*)\]\(([^)\s]*)\)/', function ($m) use ($shop) {
            $url = $m[2];
            $isShop = $shop !== '' && ($url === $shop || str_starts_with($url, $shop.'/') || str_starts_with($url, $shop.'?'));

            return $isShop ? $m[0] : $m[1];
        }, $text);
    }
}
