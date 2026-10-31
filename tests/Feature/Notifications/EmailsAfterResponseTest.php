<?php

namespace Tests\Feature\Notifications;

use App\Enums\VerificationPurpose;
use App\Models\BookVariant;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Order;
use App\Notifications\AccountReopenedNotification;
use App\Notifications\AccountSecurityNotice;
use App\Notifications\NewsletterConfirmNotification;
use App\Notifications\OrderPlacedNotification;
use App\Notifications\OrderStatusChangedNotification;
use App\Notifications\OtpCodeNotification;
use App\Notifications\ReturnUpdatedNotification;
use App\Notifications\StaffInvitationNotification;
use App\Notifications\WelcomeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\TestCase;

/**
 * "For your information" emails go out right after the API answer (same process, no worker);
 * emails someone is waiting for (codes, invitations) are still sent during the request.
 */
class EmailsAfterResponseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'sync']); // as on Render
    }

    private function sentCount(): int
    {
        return count(app('mailer')->getSymfonyTransport()->messages());
    }

    private function breakTheMailServer(): void
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
        config(['mail.default' => 'broken', 'mail.mailers.broken' => ['transport' => 'broken']]);
    }

    public function test_information_emails_are_sent_after_the_response(): void
    {
        foreach ([AccountReopenedNotification::class, AccountSecurityNotice::class, OrderPlacedNotification::class,
            OrderStatusChangedNotification::class, ReturnUpdatedNotification::class, WelcomeNotification::class] as $class) {
            $this->assertSame(['mail' => 'deferred'], (new \ReflectionClass($class))->newInstanceWithoutConstructor()->viaConnections(), $class);
        }

        Customer::factory()->unverified()->create()->notify(new AccountSecurityNotice('Your Bookly password was changed', 'The password was changed.'));

        $this->assertSame(0, $this->sentCount(), 'nothing is sent while the request is still running');
        app(DeferredCallbackCollection::class)->invoke(); // what Laravel runs once the answer has gone out
        $this->assertSame(1, $this->sentCount());
    }

    public function test_emails_someone_is_waiting_for_are_sent_during_the_request(): void
    {
        foreach ([OtpCodeNotification::class, StaffInvitationNotification::class, NewsletterConfirmNotification::class] as $class) {
            $this->assertFalse(method_exists($class, 'viaConnections'), $class);
        }

        Customer::factory()->unverified()->create()->notify(new OtpCodeNotification('123456', VerificationPurpose::EmailVerify, 10));

        $this->assertSame(1, $this->sentCount());
    }

    public function test_the_old_behaviour_comes_back_with_one_setting(): void
    {
        config(['queue.after_response_connection' => 'sync']);

        Customer::factory()->unverified()->create()->notify(new AccountSecurityNotice('Your Bookly password was changed', 'The password was changed.'));

        $this->assertSame(1, $this->sentCount());
    }

    public function test_checkout_succeeds_and_reports_the_error_when_the_mail_server_is_down(): void
    {
        Exceptions::fake();
        $this->breakTheMailServer();
        $customer = Customer::factory()->create();
        $address = CustomerAddress::factory()->for($customer)->create(['city' => 'Phnom Penh']);
        $variant = BookVariant::factory()->create(['price_usd' => '15.00', 'stock_quantity' => 5]);
        $this->withToken($customer->createToken('t', ['customer'])->plainTextToken);
        $this->postJson('/api/v1/cart/items', ['book_variant_id' => $variant->id, 'quantity' => 1])->assertCreated();

        $this->withHeader('Idempotency-Key', 'mail-outage-checkout-1')
            ->postJson('/api/v1/checkout', ['address_id' => $address->id, 'payment_method' => 'cod'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending');

        $this->assertSame(1, Order::count());
        Exceptions::assertReported(TransportException::class);
    }
}
