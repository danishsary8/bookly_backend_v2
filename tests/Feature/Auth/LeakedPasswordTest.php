<?php

namespace Tests\Feature\Auth;

use App\Models\Customer;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\NotPwnedVerifier;
use Tests\TestCase;

/** Owner 2026-11-04: a new password must not be in a known data leak (Have I Been Pwned, k-anonymity). */
class LeakedPasswordTest extends TestCase
{
    use RefreshDatabase;

    private const LEAKED = 'Leaked1234';

    private const SAFE = 'Bookly-unique-73kq';

    protected function setUp(): void
    {
        parent::setUp();
        // The real checker, with Have I Been Pwned faked: it knows LEAKED (seen 5,000 times) and nothing else.
        $this->app->instance(UncompromisedVerifier::class, new NotPwnedVerifier($this->app['Illuminate\Http\Client\Factory'], 5));
        $hash = strtoupper(sha1(self::LEAKED));
        Http::fake(['api.pwnedpasswords.com/range/*' => function (HttpRequest $request) use ($hash) {
            return str_ends_with($request->url(), '/'.substr($hash, 0, 5))
                ? Http::response(substr($hash, 5).":5000\r\n0018A45C4D1DEF81644B54AB7F969B88D65:1")
                : Http::response('0018A45C4D1DEF81644B54AB7F969B88D65:1');
        }]);
    }

    public function test_sign_up_refuses_a_leaked_password_and_accepts_a_safe_one(): void
    {
        $form = ['name' => 'Dara', 'email' => 'dara@example.test', 'password_confirmation' => null];

        $this->postJson('/api/v1/auth/register', [...$form, 'password' => self::LEAKED, 'password_confirmation' => self::LEAKED])
            ->assertUnprocessable()
            ->assertJsonPath('errors.password.0', "This password has appeared in a known data leak, so it isn't safe. Please choose a different one.");

        $this->postJson('/api/v1/auth/register', [...$form, 'password' => self::SAFE, 'password_confirmation' => self::SAFE])->assertCreated();

        // Only the first 5 characters of the SHA-1 hash ever leave the server.
        Http::assertSent(fn (HttpRequest $r) => preg_match('#/range/[0-9A-F]{5}$#', $r->url()) === 1);
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), self::LEAKED) || str_contains($r->url(), strtoupper(sha1(self::LEAKED))));
    }

    public function test_changing_the_password_refuses_a_leaked_one_in_khmer_too(): void
    {
        $customer = Customer::factory()->create(['password_hash' => self::SAFE, 'email_verified_at' => now(), 'locale' => 'km']);
        $token = $customer->createToken('t', ['customer'])->plainTextToken;

        $this->withToken($token)->withHeaders(['Accept-Language' => 'km'])
            ->putJson('/api/v1/me/password', ['current_password' => self::SAFE, 'password' => self::LEAKED, 'password_confirmation' => self::LEAKED])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_an_unreachable_leak_service_never_blocks_a_sign_up(): void
    {
        Http::fake(['api.pwnedpasswords.com/*' => Http::failedConnection()]);
        $this->app->instance(UncompromisedVerifier::class, new NotPwnedVerifier($this->app['Illuminate\Http\Client\Factory'], 5));

        $this->postJson('/api/v1/auth/register', ['name' => 'Sok', 'email' => 'sok@example.test', 'password' => self::LEAKED, 'password_confirmation' => self::LEAKED])
            ->assertCreated();
    }
}
