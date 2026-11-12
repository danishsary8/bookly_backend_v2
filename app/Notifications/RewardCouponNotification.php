<?php

namespace App\Notifications;

use App\Models\Coupon;
use App\Notifications\Concerns\SendsAfterResponse;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A reward coupon was earned ("10% off your next order"), or ends in 3 days (App\Services\Rewards\RewardCoupons). */
class RewardCouponNotification extends Notification implements ShouldQueue
{
    use Queueable, SendsAfterResponse;

    public const EARNED = 'earned';

    public const ENDING = 'ending';

    public function __construct(public readonly Coupon $coupon, public readonly string $kind) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $percent = rtrim(rtrim((string) $this->coupon->value, '0'), '.');
        $values = [
            'code' => $this->coupon->code,
            'percent' => $percent,
            'date' => $this->coupon->expires_at?->timezone(config('shop.timezone'))->format('j M Y'),
        ];
        $earned = $this->kind === self::EARNED;

        $mail = (new MailMessage)
            ->subject(__($earned ? 'mail.reward_earned_subject' : 'mail.reward_ending_subject', $values))
            ->greeting(__('mail.hello_greeting', ['name' => $notifiable->name]))
            ->line(__($earned ? 'mail.reward_earned_intro' : 'mail.reward_ending_intro', $values))
            ->line(__('mail.reward_code', $values));
        if ($this->coupon->max_discount !== null) {
            $mail->line(__('mail.reward_cap', ['amount' => $this->coupon->max_discount]));
        }
        $mail->line(__('mail.reward_until', $values));

        $shop = rtrim((string) (config('shop.url') ?: config('app.url')), '/');
        if ($shop !== '') {
            $mail->action(__('mail.reward_action'), "{$shop}/books");
        }

        return $mail;
    }
}
