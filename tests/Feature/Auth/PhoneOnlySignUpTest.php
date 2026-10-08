<?php

namespace Tests\Feature\Auth;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Owner (2026-10-08): signing up with a phone number and a Telegram code doesn't need an email. */
class PhoneOnlySignUpTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> codes Telegram was asked to deliver */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        config(['services.telegram_gateway.token' => 'gateway-token']);
        Http::fake(['gatewayapi.telegram.org/*' => function (HttpRequest $request) {
            $this->sent[] = $request['code'];

            return Http::response(['ok' => true, 'result' => ['request_id' => 'r1']]);
        }]);
    }

    private function register(array $overrides = [], string $ip = '10.0.0.1'): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/v1/auth/register', [
            'name' => 'Sok Dara', 'password' => 'secret123', 'password_confirmation' => 'secret123',
            'verify_by' => 'telegram', 'phone' => '012 345 678', ...$overrides,
        ]);
    }

    public function test_a_customer_signs_up_with_only_a_phone_number(): void
    {
        $response = $this->register()->assertCreated()
            ->assertJsonPath('verify_by', 'telegram')
            ->assertJsonPath('customer.email', null);
        $token = $response->json('token');

        $this->withToken($token)->postJson('/api/v1/auth/verify-phone', ['code' => $this->sent[0]])->assertOk()
            ->assertJsonPath('customer.verified', true)
            ->assertJsonPath('customer.phone_number', '+85512345678');
        // They sign in again with their number and a Telegram code.
        $this->withToken($token)->getJson('/api/v1/me')->assertJsonPath('data.sign_in_methods', ['phone']);
        Notification::assertNothingSent();
    }

    public function test_an_empty_email_counts_as_none(): void
    {
        $this->register(['email' => ''])->assertCreated()->assertJsonPath('customer.email', null);
    }

    public function test_an_email_given_with_a_phone_sign_up_is_kept(): void
    {
        $this->register(['email' => 'Dara@Example.com'])->assertCreated()->assertJsonPath('customer.email', 'dara@example.com');
    }

    public function test_email_sign_ups_still_need_an_email(): void
    {
        $this->register(['verify_by' => 'email'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertSame(0, Customer::count());
    }

    public function test_signing_up_again_with_the_same_number_takes_over_the_unfinished_sign_up(): void
    {
        $first = $this->register()->assertCreated()->json('customer.id');
        $this->travel(11)->minutes(); // past the per-visitor send limit

        $this->register(['name' => 'Sok Dara Chan'], '10.0.0.2')->assertCreated()->assertJsonPath('customer.id', $first);
        $this->assertSame(1, Customer::count());
        $this->assertSame('Sok Dara Chan', Customer::find($first)->name);
    }

    public function test_a_verified_number_is_still_refused(): void
    {
        Customer::factory()->create(['phone_e164' => '+85512345678', 'phone_verified_at' => now()]);

        $this->register()->assertUnprocessable()->assertJsonValidationErrors(['phone' => 'already on another']);
    }

    public function test_an_email_taken_by_another_account_is_still_refused(): void
    {
        Customer::factory()->create(['email' => 'taken@example.com']);

        $this->register(['email' => 'taken@example.com'])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_an_unfinished_phone_only_sign_up_is_cleaned_up_after_48_hours(): void
    {
        $this->register()->assertCreated();
        $this->travel(49)->hours();

        $this->artisan('customers:prune-unverified')->assertSuccessful();
        $this->assertSame(0, Customer::count());
    }
}
