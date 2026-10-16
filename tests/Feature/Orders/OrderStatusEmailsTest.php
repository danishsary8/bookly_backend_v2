<?php

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Notifications\OrderStatusChangedNotification;
use App\Notifications\ReturnUpdatedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\Concerns\ActsAsStaff;
use Tests\Concerns\BuildsOrders;
use Tests\TestCase;

class OrderStatusEmailsTest extends TestCase
{
    use ActsAsStaff, BuildsOrders, RefreshDatabase;

    private Customer $customer;

    private string $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->customer = Customer::factory()->create(['email' => 'dara@example.com']);
        $this->staff = $this->staffToken();
    }

    private function pendingOrder(?Customer $customer = null): Order
    {
        $order = Order::factory()->for($customer ?? $this->customer)->create(['status' => OrderStatus::Pending]);
        $order->statusHistory()->create(['status' => OrderStatus::Pending]);

        return $order;
    }

    private function setStatus(Order $order, string $status, ?string $note = null)
    {
        return $this->asToken($this->staff)->postJson("/api/v1/staff/orders/{$order->id}/status", ['status' => $status, 'note' => $note]);
    }

    public function test_every_staff_step_emails_the_customer_and_shipped_carries_the_tracking_note(): void
    {
        Notification::fake();
        $order = $this->pendingOrder();

        $this->setStatus($order, 'processing')->assertOk();
        $this->setStatus($order, 'shipped', 'Courier J&T, tracking 123')->assertOk();
        $this->setStatus($order, 'delivered')->assertOk();

        Notification::assertSentToTimes($this->customer, OrderStatusChangedNotification::class, 3);
        Notification::assertSentTo($this->customer, OrderStatusChangedNotification::class, function ($n) {
            if ($n->status !== OrderStatus::Shipped) {
                return false;
            }
            $text = implode("\n", $n->toMail($this->customer)->introLines);

            return str_contains($text, 'tracking 123') && str_contains($n->toMail($this->customer)->subject, 'on its way');
        });
        Notification::assertSentTo($this->customer, OrderStatusChangedNotification::class, function ($n) {
            return $n->status === OrderStatus::Delivered && str_contains(implode("\n", $n->toMail($this->customer)->introLines), 'Receipt:');
        });
    }

    public function test_cancel_by_staff_and_by_customer_both_send_a_cancelled_email(): void
    {
        Notification::fake();
        $byStaff = $this->pendingOrder();
        $this->setStatus($byStaff, 'cancelled', 'Out of stock')->assertOk();

        $byCustomer = $this->pendingOrder();
        $token = $this->customer->createToken('t', ['customer'])->plainTextToken;
        $this->asToken($token)->postJson("/api/v1/orders/{$byCustomer->id}/cancel", ['reason' => 'Changed my mind'])->assertOk();

        Notification::assertSentToTimes($this->customer, OrderStatusChangedNotification::class, 2);
    }

    public function test_a_rejected_status_change_sends_nothing(): void
    {
        Notification::fake();
        $order = $this->pendingOrder();

        $this->setStatus($order, 'delivered')->assertUnprocessable();

        Notification::assertNothingSent();
    }

    public function test_return_steps_each_email_the_customer(): void
    {
        Notification::fake();
        $book = $this->physical();
        $order = $this->deliveredOrder($this->customer, [[$book, 1]]);
        $token = $this->customer->createToken('t', ['customer'])->plainTextToken;
        $itemId = $order->items->first()->id;

        $id = $this->asToken($token)->postJson("/api/v1/orders/{$order->id}/returns", ['reason' => 'Damaged', 'items' => [['order_item_id' => $itemId, 'quantity' => 1]]])
            ->assertCreated()->json('data.id');
        foreach (['approve', 'refund'] as $step) {
            $this->asToken($this->staff)->postJson("/api/v1/staff/returns/{$id}/{$step}", ['note' => 'ok', 'method' => 'cash'])->assertOk();
        }

        $second = $this->deliveredOrder($this->customer, [[$this->physical(), 1]]);
        $second2 = $this->asToken($token)->postJson("/api/v1/orders/{$second->id}/returns", ['reason' => 'Late', 'items' => [['order_item_id' => $second->items->first()->id, 'quantity' => 1]]])
            ->assertCreated()->json('data.id');
        $this->asToken($this->staff)->postJson("/api/v1/staff/returns/{$second2}/reject", ['note' => 'Used books'])->assertOk();

        // requested, approved, refunded + requested, rejected
        Notification::assertSentToTimes($this->customer, ReturnUpdatedNotification::class, 5);
        Notification::assertSentTo($this->customer, ReturnUpdatedNotification::class, function ($n) {
            return $n->return->status->value === 'refunded' && str_contains(implode("\n", $n->toMail($this->customer)->introLines), 'Refund amount:');
        });
    }

    public function test_phone_only_customers_are_skipped_without_error(): void
    {
        Notification::fake();
        $phoneOnly = Customer::factory()->create(['email' => null]);

        $this->setStatus($this->pendingOrder($phoneOnly), 'processing')->assertOk();

        Notification::assertNothingSent();
    }

    public function test_a_mail_outage_never_blocks_the_status_change(): void
    {
        Mail::extend('broken', fn () => new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                throw new TransportException('SMTP is down');
            }

            public function __toString(): string
            {
                return 'broken://';
            }
        });
        config(['mail.default' => 'broken', 'mail.mailers.broken' => ['transport' => 'broken'], 'queue.default' => 'sync']);
        $order = $this->pendingOrder();

        $this->setStatus($order, 'processing')->assertOk()->assertJsonPath('data.status', 'processing');

        $this->assertSame(OrderStatus::Processing, $order->fresh()->status);
        $this->assertCount(2, $order->statusHistory);
    }
}
