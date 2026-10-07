<?php

namespace Tests\Feature\Auth;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PhoneSignInTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{phone: string, code: string}> what Telegram was asked to deliver */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram_gateway.token' => 'gateway-token']);
        $this->gateway();
    }

    private function gateway(?string $error = null): void
    {
        Http::fake(['gateway.telegram.org/*' => function (HttpRequest $request) use ($error) {
            if ($error !== null) {
                return Http::response(['ok' => false, 'error' => $error]);
            }
            $this->sent[] = ['phone' => $request['phone_number'], 'code' => $request['code']];

            return Http::response(['ok' => true, 'result' => ['request_id' => 'r1']]);
        }]);
    }

    private function customer(array $attributes = []): Customer
    {
        return Customer::factory()->create(['phone_e164' => '+85512345678', 'phone_verified_at' => now(), ...$attributes]);
    }

    private function askForCode(string $phone = '012 345 678', string $ip = '10.0.0.1')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/v1/auth/phone-login', ['phone' => $phone]);
    }

    public function test_a_customer_signs_in_with_their_number_and_a_telegram_code(): void
    {
        $customer = $this->customer();

        $this->askForCode()->assertOk()->assertJsonPath('message', 'If this number has a Bookly account, we sent a code to its Telegram.');
        $this->assertSame('+85512345678', $this->sent[0]['phone']);

        $this->postJson('/api/v1/auth/phone-login/verify', ['phone' => '+855 12 345 678', 'code' => '000000'])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson('/api/v1/auth/phone-login/verify', ['phone' => '+855 12 345 678', 'code' => $this->sent[0]['code']])
            ->assertOk()
            ->assertJsonPath('customer.id', $customer->id)
            ->assertJsonStructure(['token', 'expires_at']);
        // The code works once.
        $this->postJson('/api/v1/auth/phone-login/verify', ['phone' => '012345678', 'code' => $this->sent[0]['code']])->assertUnprocessable();
    }

    public function test_unknown_numbers_get_the_same_answer_and_no_code(): void
    {
        $this->askForCode('096 111 2222')->assertOk()->assertJsonPath('message', 'If this number has a Bookly account, we sent a code to its Telegram.');
        $this->assertSame([], $this->sent);
    }

    public function test_numbers_that_were_never_proven_get_no_code(): void
    {
        // A number waiting for its first code isn't saved on the account; an old contact number isn't proven.
        Customer::factory()->create(['phone' => '012 345 678', 'phone_e164' => null, 'phone_verified_at' => null]);

        $this->askForCode()->assertOk();
        $this->assertSame([], $this->sent);
    }

    public function test_closed_and_deactivated_accounts_get_no_code(): void
    {
        $this->customer(['is_active' => false]);
        $this->askForCode()->assertOk();
        Customer::query()->delete();
        $this->customer(['phone_e164' => '+85598765432'])->delete();
        $this->askForCode('098 765 432')->assertOk();

        $this->assertSame([], $this->sent);
    }

    public function test_a_code_for_one_number_does_not_sign_in_another(): void
    {
        $this->customer();
        $this->customer(['phone_e164' => '+85598765432']);
        $this->askForCode()->assertOk();

        $this->postJson('/api/v1/auth/phone-login/verify', ['phone' => '098 765 432', 'code' => $this->sent[0]['code']])->assertUnprocessable();
    }

    public function test_five_wrong_codes_throw_the_code_away(): void
    {
        $this->customer();
        $this->askForCode()->assertOk();

        for ($i = 0; $i < 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.1.0.{$i}"])
                ->postJson('/api/v1/auth/phone-login/verify', ['phone' => '012345678', 'code' => '000000'])->assertUnprocessable();
        }
        $this->postJson('/api/v1/auth/phone-login/verify', ['phone' => '012345678', 'code' => $this->sent[0]['code']])->assertUnprocessable();
    }

    public function test_the_number_limits_apply_to_every_number_alike(): void
    {
        $this->customer();
        for ($i = 1; $i <= 3; $i++) {
            $this->askForCode('012 345 678', "10.2.0.{$i}")->assertOk();
            $this->askForCode('096 111 2222', "10.3.0.{$i}")->assertOk();
        }

        $this->askForCode('012 345 678', '10.2.0.9')->assertUnprocessable()->assertJsonValidationErrors(['phone' => 'This number has had 3 codes in the last hour']);
        $this->askForCode('096 111 2222', '10.3.0.9')->assertUnprocessable()->assertJsonValidationErrors(['phone' => 'This number has had 3 codes in the last hour']);
        $this->assertCount(3, $this->sent);
    }

    public function test_a_telegram_failure_looks_like_any_other_answer(): void
    {
        $this->gateway('PHONE_NUMBER_NOT_FOUND');
        $this->customer();

        $this->askForCode()->assertOk()->assertJsonPath('message', 'If this number has a Bookly account, we sent a code to its Telegram.');
    }

    public function test_off_while_telegram_codes_are_off(): void
    {
        config(['services.telegram_gateway.token' => null]);
        $this->customer();

        $this->askForCode()->assertStatus(503);
        $this->assertSame([], $this->sent);
    }

    public function test_only_cambodian_numbers(): void
    {
        $this->askForCode('+1 415 555 0100')->assertUnprocessable()->assertJsonValidationErrors('phone');
    }

    public function test_a_verified_phone_is_a_way_in_while_telegram_codes_are_on(): void
    {
        $customer = $this->customer(['password_hash' => null, 'google_id' => 'g-1']);
        $token = $customer->createToken('t', ['customer'])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/me')->assertJsonPath('data.sign_in_methods', ['google', 'phone']);
        // So Google can go: the phone still gets them in.
        $this->withToken($token)->deleteJson('/api/v1/me/connections/google')->assertOk();

        config(['services.telegram_gateway.token' => null]);
        $this->withToken($token)->getJson('/api/v1/me')->assertJsonPath('data.sign_in_methods', []);
    }
}
