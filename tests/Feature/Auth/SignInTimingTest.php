<?php

namespace Tests\Feature\Auth;

use App\Models\Customer;
use App\Models\StaffUser;
use App\Support\TimingSafe;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

/**
 * Security review 2026-11-03: an email without an account must not answer faster than one with an account,
 * or the response time tells a bot who is a customer or staff member.
 */
class SignInTimingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private int $checks = 0;

    private bool $counting = false;

    /** Counts real password-hash checks during a request (the slow, timing-relevant work). */
    private function hashChecksFor(callable $request): int
    {
        if (! $this->counting) {
            $this->counting = true;
            $real = $this->app->make('hash');
            Hash::shouldReceive('check')->andReturnUsing(function (...$args) use ($real) {
                $this->checks++;

                return $real->check(...$args);
            });
            foreach (['make', 'needsRehash', 'isHashed', 'info'] as $method) {
                Hash::shouldReceive($method)->andReturnUsing(fn (...$args) => $real->{$method}(...$args));
            }
            Hash::shouldReceive('verifyConfiguration')->andReturn(true);
        }
        $this->checks = 0;
        $request();

        return $this->checks;
    }

    public function test_customer_sign_in_does_one_password_check_whether_or_not_the_account_exists(): void
    {
        Customer::factory()->create(['email' => 'known@example.test', 'password_hash' => 'Secret123']);
        Customer::factory()->create(['email' => 'social@example.test', 'password_hash' => null]); // Google/Telegram only
        $login = fn (string $email) => fn () => $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'Wrong1234'])->assertUnauthorized();

        $this->assertSame(1, $this->hashChecksFor($login('known@example.test')));
        $this->assertSame(1, $this->hashChecksFor($login('nobody@example.test')));
        $this->assertSame(1, $this->hashChecksFor($login('social@example.test')));
    }

    public function test_the_right_password_still_signs_in_and_a_missing_account_never_does(): void
    {
        Customer::factory()->create(['email' => 'known@example.test', 'password_hash' => 'Secret123']);

        $this->postJson('/api/v1/auth/login', ['email' => 'known@example.test', 'password' => 'Secret123'])->assertOk()->assertJsonStructure(['token']);
        $this->assertFalse(TimingSafe::check('anything', null));
        $this->assertFalse(TimingSafe::check('', ''));
    }

    public function test_staff_sign_in_does_one_password_check_whether_or_not_the_account_exists(): void
    {
        StaffUser::factory()->create(['email' => 'clerk@bookly.test']);
        $login = fn (string $email) => fn () => $this->postJson('/api/v1/staff/auth/login', ['email' => $email, 'password' => 'Wrong1234'])->assertUnauthorized();

        $this->assertSame(1, $this->hashChecksFor($login('clerk@bookly.test')));
        $this->assertSame(1, $this->hashChecksFor($login('nobody@bookly.test')));
    }

    public function test_work_after_the_response_runs_at_the_end_and_a_failure_is_only_reported(): void
    {
        $ran = [];
        TimingSafe::afterResponse(function () use (&$ran) {
            $ran[] = 'code emailed';
        });
        TimingSafe::afterResponse(fn () => throw new RuntimeException('mail server down'));
        $this->assertSame([], $ran); // nothing yet: the answer goes out first

        $reported = [];
        $this->app->make(ExceptionHandler::class)->reportable(function (RuntimeException $e) use (&$reported) {
            $reported[] = $e->getMessage();

            return false;
        });
        $this->app->terminate();

        $this->assertSame(['code emailed'], $ran);
        $this->assertSame(['mail server down'], $reported);
    }
}
