<?php

namespace Tests\Feature\Platform;

use App\Models\BookVariant;
use App\Models\Coupon;
use App\Models\Customer;
use App\Notifications\AccountSecurityNotice;
use App\Services\Telegram\TelegramBot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\ActsAsStaff;
use Tests\TestCase;

/** The limits the owner chose on 2026-10-13 ("looser" set, docs/RATE_LIMITS.md). */
class RateLimitsTest extends TestCase
{
    use ActsAsStaff, RefreshDatabase;

    private function customerToken(?Customer $customer = null): string
    {
        return ($customer ?? Customer::factory()->create())->createToken('t', ['customer'])->plainTextToken;
    }

    /** Fires $times requests and returns the status codes. */
    private function hit(int $times, callable $request): array
    {
        return array_map(fn () => $request()->getStatusCode(), range(1, $times));
    }

    public function test_checkout_allows_5_tries_per_5_minutes_and_20_per_hour(): void
    {
        $token = $this->customerToken();
        $checkout = fn () => $this->asToken($token)->withHeader('Idempotency-Key', 'key-'.uniqid('', true))->postJson('/api/v1/checkout', []);

        $codes = $this->hit(6, $checkout);
        $this->assertNotContains(429, array_slice($codes, 0, 5));
        $this->assertSame(429, $codes[5]);

        // Each 5 minutes 5 more, until 20 in the hour.
        foreach ([1, 2, 3] as $_) {
            $this->travel(5)->minutes();
            $this->assertNotContains(429, $this->hit(5, $checkout));
        }
        $this->travel(5)->minutes();
        $this->assertSame(429, $checkout()->getStatusCode(), 'the 21st try within the hour');
    }

    public function test_one_customer_hitting_the_checkout_limit_does_not_block_another_on_the_same_ip(): void
    {
        $first = $this->customerToken();
        $this->hit(6, fn () => $this->asToken($first)->withHeader('Idempotency-Key', 'key-'.uniqid('', true))->postJson('/api/v1/checkout', []));

        $this->asToken($this->customerToken())->withHeader('Idempotency-Key', 'key-other-1')->postJson('/api/v1/checkout', [])
            ->assertStatus(422);
    }

    public function test_five_unknown_coupon_codes_in_a_row_lock_checking_for_15_minutes(): void
    {
        $customer = Customer::factory()->create();
        $token = $this->customerToken($customer);
        $variant = BookVariant::factory()->create(['price_usd' => '20.00', 'stock_quantity' => 5]);
        $this->asToken($token)->postJson('/api/v1/cart/items', ['book_variant_id' => $variant->id, 'quantity' => 1])->assertCreated();
        $good = Coupon::factory()->create(['code' => 'REAL10']);
        $check = fn (string $code) => $this->asToken($token)->postJson('/api/v1/cart/coupon/check', ['code' => $code]);

        // A real code that can't be used (expired) is not a guess and doesn't count.
        Coupon::factory()->create(['code' => 'OLD10', 'expires_at' => now()->subDay()]);
        $check('OLD10')->assertUnprocessable();

        foreach (['AAAA1', 'AAAA2', 'AAAA3', 'AAAA4'] as $code) {
            $check($code)->assertUnprocessable();
        }
        $check('REAL10')->assertOk(); // a right code clears the count
        $this->travel(61)->seconds(); // stay under the separate 10-a-minute limit
        foreach (['BBBB1', 'BBBB2', 'BBBB3', 'BBBB4', 'BBBB5'] as $code) {
            $check($code)->assertUnprocessable();
        }

        $check('REAL10')->assertStatus(429)->assertHeader('Retry-After', '900')
            ->assertJsonPath('message', 'Too many wrong coupon codes. Please try again in 15 minutes.');

        $this->travel(16)->minutes();
        $check('REAL10')->assertOk()->assertJsonPath('data.code', $good->code);
    }

    public function test_coupon_checking_allows_10_per_minute(): void
    {
        $token = $this->customerToken();
        $codes = $this->hit(11, fn () => $this->asToken($token)->postJson('/api/v1/cart/coupon/check', ['code' => 'X']));

        $this->assertSame(429, $codes[10]);
        $this->assertNotContains(429, array_slice($codes, 0, 10));
    }

    public function test_telegram_status_allows_60_checks_a_minute_per_link(): void
    {
        config(['services.telegram_bot.token' => '123:bot-token']);
        $status = fn (string $key) => $this->postJson('/api/v1/auth/telegram/status', ['key' => $key]);

        $codes = $this->hit(61, fn () => $status('link-a'));
        $this->assertNotContains(429, array_slice($codes, 0, 60), 'the website asks every 2 seconds: 30 a minute');
        $this->assertSame(429, $codes[60]);
        $this->assertNotSame(429, $status('link-b')->getStatusCode(), 'another link is counted on its own');
    }

    public function test_book_search_allows_60_a_minute_and_browsing_120(): void
    {
        $search = $this->hit(61, fn () => $this->getJson('/api/v1/books?q=holmes'));
        $this->assertNotContains(429, array_slice($search, 0, 60));
        $this->assertSame(429, $search[60]);

        $this->travel(2)->minutes();
        $this->assertNotContains(429, $this->hit(100, fn () => $this->getJson('/api/v1/books')));
    }

    public function test_dashboard_allows_30_a_minute_per_staff_member(): void
    {
        $admin = $this->staffToken(admin: true);
        $codes = $this->hit(31, fn () => $this->asToken($admin)->getJson('/api/v1/staff/dashboard/summary?compare=0'));

        $this->assertNotContains(429, array_slice($codes, 0, 30));
        $this->assertSame(429, $codes[30]);
    }

    public function test_telegram_with_the_right_secret_is_never_limited_but_fakes_are(): void
    {
        config(['services.telegram_bot.token' => '123:bot-token', 'services.telegram_bot.username' => 'BooklyBot']);
        Http::fake();
        $secret = app(TelegramBot::class)->webhookSecret();
        $update = ['update_id' => 1, 'message' => ['chat' => ['id' => 5, 'type' => 'private'], 'from' => ['id' => 5], 'text' => 'hello']];

        $real = $this->hit(130, fn () => $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $secret)->postJson('/api/v1/telegram/webhook', $update));
        $this->assertNotContains(429, $real);

        $fake = $this->hit(121, fn () => $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'wrong')->postJson('/api/v1/telegram/webhook', $update));
        $this->assertSame(429, $fake[120]);
    }

    public function test_changing_the_password_sends_a_security_email(): void
    {
        Notification::fake();
        $customer = Customer::factory()->create(['password_hash' => 'old-password1']);

        $this->asToken($this->customerToken($customer))->putJson('/api/v1/me/password', [
            'current_password' => 'old-password1', 'password' => 'new-password2', 'password_confirmation' => 'new-password2',
        ])->assertOk();

        Notification::assertSentTo($customer, AccountSecurityNotice::class, fn ($n) => $n->subject === 'Your Bookly password was changed');
    }

    public function test_a_wrong_current_password_sends_nothing(): void
    {
        $customer = Customer::factory()->create(['password_hash' => 'old-password1']);
        Notification::fake();

        $this->asToken($this->customerToken($customer))->putJson('/api/v1/me/password', [
            'current_password' => 'nope-nope1', 'password' => 'new-password2', 'password_confirmation' => 'new-password2',
        ])->assertUnprocessable();

        Notification::assertNothingSentTo($customer);
    }
}
