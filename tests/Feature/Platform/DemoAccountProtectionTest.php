<?php

namespace Tests\Feature\Platform;

use App\Models\Customer;
use App\Support\SafeNotifier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/** The docs page publishes the demo customer's password: no visitor may take the account away from the next one. */
class DemoAccountProtectionTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(string $email): Customer
    {
        $customer = Customer::factory()->create(['email' => $email, 'password_hash' => DemoSeeder::PASSWORD]);
        $this->withToken($customer->createToken('t', ['customer'])->plainTextToken);

        return $customer;
    }

    public function test_the_demo_account_cannot_change_its_sign_in_details_or_be_deleted(): void
    {
        $demo = $this->signIn(DemoSeeder::EMAIL);
        $locked = "The shared demo account can't change this. Create your own account to try it.";

        $this->putJson('/api/v1/me/password', ['current_password' => DemoSeeder::PASSWORD, 'password' => 'NewPass123', 'password_confirmation' => 'NewPass123'])
            ->assertForbidden()->assertJson(['message' => $locked]);
        $this->deleteJson('/api/v1/me', ['password' => DemoSeeder::PASSWORD])->assertForbidden();
        $this->postJson('/api/v1/me/email', ['email' => 'mine@example.com'])->assertForbidden();
        $this->postJson('/api/v1/me/email/verify', ['code' => '123456'])->assertForbidden();
        $this->postJson('/api/v1/me/connections/google', ['token' => 'x'])->assertForbidden();
        $this->deleteJson('/api/v1/me/connections/google')->assertForbidden();
        $this->deleteJson('/api/v1/me/telegram')->assertForbidden();
        $this->postJson('/api/v1/auth/telegram/phone', [])->assertForbidden();

        $this->assertTrue(Hash::check(DemoSeeder::PASSWORD, $demo->fresh()->password_hash));
        $this->assertNull($demo->fresh()->deleted_at ?? null);
    }

    public function test_other_customers_can_still_change_their_password(): void
    {
        $this->signIn('reader@example.com');

        $this->putJson('/api/v1/me/password', ['current_password' => DemoSeeder::PASSWORD, 'password' => 'NewPass123', 'password_confirmation' => 'NewPass123'])
            ->assertOk();
    }

    public function test_the_demo_email_is_not_locked_by_the_shared_per_email_login_limit(): void
    {
        $keys = fn (string $email) => collect(RateLimiter::limiter('auth')(Request::create('/api/v1/auth/login', 'POST', ['email' => $email])))
            ->map(fn ($limit) => $limit->key)->all();

        $this->assertNotContains('email|'.DemoSeeder::EMAIL, $keys(DemoSeeder::EMAIL));
        $this->assertContains('email|reader@example.com', $keys('reader@example.com'));
    }

    public function test_no_email_is_sent_to_reserved_test_addresses(): void
    {
        Notification::fake();
        $demo = Customer::factory()->create(['email' => DemoSeeder::EMAIL]);
        $real = Customer::factory()->create(['email' => 'reader@example.com']);
        $notification = new class extends \Illuminate\Notifications\Notification
        {
            public function via(object $notifiable): array
            {
                return ['mail'];
            }
        };

        SafeNotifier::toCustomer($demo, $notification);
        SafeNotifier::toCustomer($real, $notification);

        Notification::assertNothingSentTo($demo);
        Notification::assertSentTo($real, get_class($notification));
    }
}
