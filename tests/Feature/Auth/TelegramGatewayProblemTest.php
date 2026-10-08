<?php

namespace Tests\Feature\Auth;

use App\Exceptions\TelegramGatewayProblem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** When Telegram refuses for a reason that is ours to fix, the owner hears about it (Sentry → Telegram alert). */
class TelegramGatewayProblemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Exceptions::fake();
        config(['services.telegram_gateway.token' => 'gateway-token']);
    }

    private function signUpWithTelegram()
    {
        return $this->postJson('/api/v1/auth/register', [
            'name' => 'Sok Dara', 'password' => 'secret123', 'password_confirmation' => 'secret123',
            'verify_by' => 'telegram', 'phone' => '081 668 444',
        ]);
    }

    public function test_no_balance_is_reported_with_telegrams_reason_and_a_hint(): void
    {
        Http::fake(['gateway.telegram.org/*' => Http::response(['ok' => false, 'error' => 'BALANCE_NOT_ENOUGH'])]);

        $this->signUpWithTelegram()->assertUnprocessable()
            ->assertJsonValidationErrors(['phone' => "Telegram codes aren't available right now"]);

        Exceptions::assertReported(fn (TelegramGatewayProblem $e) => str_contains($e->getMessage(), 'BALANCE_NOT_ENOUGH')
            && str_contains($e->getMessage(), 'Top up the balance at gateway.telegram.org'));
    }

    public function test_a_wrong_token_is_reported(): void
    {
        Http::fake(['gateway.telegram.org/*' => Http::response(['ok' => false, 'error' => 'ACCESS_TOKEN_INVALID'], 401)]);

        $this->signUpWithTelegram()->assertUnprocessable();

        Exceptions::assertReported(fn (TelegramGatewayProblem $e) => str_contains($e->getMessage(), 'ACCESS_TOKEN_INVALID')
            && str_contains($e->getMessage(), 'TELEGRAM_GATEWAY_TOKEN'));
    }

    public function test_an_outage_is_reported(): void
    {
        Http::fake(['gateway.telegram.org/*' => fn () => throw new ConnectionException('timed out')]);

        $this->signUpWithTelegram()->assertUnprocessable();

        Exceptions::assertReported(fn (TelegramGatewayProblem $e) => str_contains($e->getMessage(), 'could not be reached'));
    }

    public function test_a_number_without_telegram_is_the_customers_to_fix_and_not_reported(): void
    {
        Http::fake(['gateway.telegram.org/*' => Http::response(['ok' => false, 'error' => 'PHONE_NUMBER_NOT_FOUND'])]);

        $this->signUpWithTelegram()->assertUnprocessable()
            ->assertJsonValidationErrors(['phone' => 'Check it has Telegram']);

        Exceptions::assertNotReported(TelegramGatewayProblem::class);
    }
}
