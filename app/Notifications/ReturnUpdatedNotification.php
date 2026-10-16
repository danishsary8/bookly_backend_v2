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
            ReturnStatus::Requested => [__('mail.return_requested_subject', ['number' => $number]), __('mail.return_requested_intro')],
            ReturnStatus::Approved => [__('mail.return_approved_subject', ['number' => $number]), __('mail.return_approved_intro')],
            ReturnStatus::Rejected => [__('mail.return_rejected_subject', ['number' => $number]), __('mail.return_rejected_intro')],
            ReturnStatus::Refunded => [__('mail.return_refunded_subject', ['number' => $number]), __('mail.return_refunded_intro')],
        };

        $mail = (new MailMessage)
            ->subject($subject)
            ->greeting(__('mail.welcome_greeting', ['name' => $notifiable->name]))
            ->line($intro)
            ->line(__('mail.order_number', ['number' => $number]));

        if ($return->status === ReturnStatus::Refunded) {
            $mail->line(__('mail.refund_amount', ['amount' => $return->refund_amount]));
            $coupon = $return->storeCreditCoupon;
            match ($return->refund_method) {
                'cash' => $mail->line(__('mail.refund_cash')),
                'bank_transfer' => $mail->line(filled($return->refund_reference)
                    ? __('mail.refund_bank_transfer_reference', ['reference' => $return->refund_reference])
                    : __('mail.refund_bank_transfer')),
                'store_credit' => $coupon ? $mail->line(__('mail.refund_store_credit', [
                    'code' => $coupon->code,
                    'amount' => $coupon->value,
                    'date' => $coupon->expires_at->timezone(config('shop.timezone'))->format('j M Y'),
                ])) : null,
                default => null,
            };
        }

        if (filled($return->staff_note) && $return->status !== ReturnStatus::Requested) {
            $mail->line(__('mail.shop_note', ['note' => $return->staff_note]));
        }

        return $mail;
    }
}
