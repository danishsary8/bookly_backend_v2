<?php

namespace App\Notifications;

use App\Enums\ReturnStatus;
use App\Models\OrderReturn;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** One email per return step: requested, approved, rejected, refunded. */
class ReturnUpdatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly OrderReturn $return) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $return = $this->return->loadMissing('order');
        $number = $return->order->order_number;

        [$subject, $intro] = match ($return->status) {
            ReturnStatus::Requested => ["We received your return request for {$number}", 'We received your return request. We will review it and email you the result.'],
            ReturnStatus::Approved => ["Your return for {$number} was approved", 'Your return was approved. Please send the books back as agreed with the shop.'],
            ReturnStatus::Rejected => ["Your return for {$number} was rejected", 'Sorry, your return request was rejected.'],
            ReturnStatus::Refunded => ["Your refund for {$number} is done", 'We received your books and paid your refund.'],
        };

        $mail = (new MailMessage)
            ->subject($subject)
            ->greeting("Hello {$notifiable->name},")
            ->line($intro)
            ->line("Order number: {$number}");

        if ($return->status === ReturnStatus::Refunded) {
            $mail->line("Refund amount: {$return->refund_amount} USD (the shipping fee is not refunded).");
        }

        if (filled($return->staff_note) && $return->status !== ReturnStatus::Requested) {
            $mail->line("Note from the shop: {$return->staff_note}");
        }

        return $mail;
    }
}
