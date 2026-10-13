<?php

namespace Tests\Feature\Platform;

use App\Services\Telegram\TelegramBot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class TelegramHealthTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = '123:health-test-token';

    private const URL = 'https://api.telegram.org/bot'.self::TOKEN.'/getWebhookInfo';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.telegram_bot.token' => self::TOKEN,
            'services.telegram_bot.url' => 'https://api.telegram.org',
            'app.url' => 'https://bookly-api.example.com',
            'queue.default' => 'sync',
        ]);
        Http::preventStrayRequests();
        $this->freezeTime();
    }

    private function webhookInfo(array $info = []): void
    {
        Http::fake([self::URL => Http::response([
            'ok' => true,
            'result' => array_replace(['url' => url('/api/v1/telegram/webhook')], $info),
        ])]);
    }

    public function test_bot_is_off_without_a_token_and_no_request_is_sent(): void
    {
        config(['services.telegram_bot.token' => null]);
        Http::fake();

        $this->getJson('/api/v1/health')->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.telegram_bot', ['status' => 'off']);
        Http::assertNothingSent();
    }

    public function test_matching_webhook_is_ok(): void
    {
        $this->webhookInfo();

        $response = $this->getJson('/api/v1/health')->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.telegram_bot', ['status' => 'ok']);
        $this->assertStringNotContainsString(self::TOKEN, $response->getContent());
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->url() === self::URL);
        Http::assertSentCount(1);
    }

    public function test_webhook_matches_the_request_host_even_when_app_url_differs(): void
    {
        config(['app.url' => 'https://configured-api.example.com']);
        $this->webhookInfo(['url' => 'https://live-api.example.com/api/v1/telegram/webhook']);

        $this->getJson('https://live-api.example.com/api/v1/health')->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.telegram_bot', ['status' => 'ok']);
        Http::assertSentCount(1);
    }

    public function test_missing_webhook_degrades_health(): void
    {
        $this->webhookInfo(['url' => '']);

        $this->getJson('/api/v1/health')->assertOk()
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.telegram_bot', ['status' => 'degraded', 'error' => 'webhook not set']);
    }

    public function test_wrong_webhook_degrades_health_without_exposing_its_url(): void
    {
        $this->webhookInfo(['url' => 'https://wrong.example.com/'.self::TOKEN]);

        $response = $this->getJson('/api/v1/health')->assertOk()
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.telegram_bot.error', 'webhook not set');
        $this->assertStringNotContainsString(self::TOKEN, $response->getContent());
    }

    public function test_recent_delivery_error_degrades_health(): void
    {
        $this->webhookInfo(['last_error_date' => now()->subMinutes(29)->timestamp, 'last_error_message' => 'Wrong response: 404 Not Found']);

        $this->getJson('/api/v1/health')->assertOk()
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.telegram_bot', ['status' => 'degraded', 'error' => 'Wrong response: 404 Not Found']);
    }

    public function test_delivery_errors_older_than_thirty_minutes_are_ignored_even_while_cached(): void
    {
        $this->webhookInfo(['last_error_date' => now()->subMinutes(29)->timestamp, 'last_error_message' => 'Timeout']);
        $this->getJson('/api/v1/health')->assertJsonPath('status', 'degraded');

        $this->travel(2)->minutes();

        $this->getJson('/api/v1/health')->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.telegram_bot', ['status' => 'ok']);
        Http::assertSentCount(1);
    }

    public function test_an_undated_error_is_ignored(): void
    {
        $this->webhookInfo(['last_error_message' => 'Old timeout']);

        $this->getJson('/api/v1/health')->assertOk()->assertJsonPath('checks.telegram_bot.status', 'ok');
    }

    public function test_unreachable_telegram_degrades_health_and_caches_the_failure(): void
    {
        Http::fake([self::URL => Http::failedConnection('Cannot reach '.self::URL)]);

        foreach ([0, 5] as $minutes) {
            $this->travel($minutes)->minutes();
            $response = $this->getJson('/api/v1/health')->assertOk()
                ->assertJsonPath('status', 'degraded')
                ->assertJsonPath('checks.telegram_bot', ['status' => 'degraded', 'error' => 'Telegram unreachable']);
            $this->assertStringNotContainsString(self::TOKEN, $response->getContent());
        }
        Http::assertSentCount(1);
    }

    public function test_telegram_refusal_never_exposes_the_token(): void
    {
        Http::fake([self::URL => Http::response(['ok' => false, 'description' => 'Unauthorized '.self::TOKEN], 401)]);

        $response = $this->getJson('/api/v1/health')->assertOk()
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.telegram_bot.error', 'Telegram unreachable');
        $this->assertStringNotContainsString(self::TOKEN, $response->getContent());
    }

    public function test_delivery_error_redacts_the_token(): void
    {
        $this->webhookInfo(['last_error_date' => now()->timestamp, 'last_error_message' => 'Failed for '.self::TOKEN]);

        $response = $this->getJson('/api/v1/health')->assertOk()
            ->assertJsonPath('checks.telegram_bot.error', 'Failed for ***');
        $this->assertStringNotContainsString(self::TOKEN, $response->getContent());
    }

    public function test_webhook_info_is_cached_for_ten_minutes_then_refreshed(): void
    {
        Http::fake([self::URL => Http::sequence()
            ->push(['ok' => true, 'result' => ['url' => url('/api/v1/telegram/webhook')]])
            ->push(['ok' => true, 'result' => ['url' => '']])]);

        $this->getJson('/api/v1/health')->assertJsonPath('checks.telegram_bot.status', 'ok');
        $this->travel(5)->minutes();
        $this->getJson('/api/v1/health')->assertJsonPath('checks.telegram_bot.status', 'ok');
        Http::assertSentCount(1);

        $this->travel(5)->minutes();
        $this->getJson('/api/v1/health')->assertJsonPath('checks.telegram_bot.error', 'webhook not set');
        Http::assertSentCount(2);
    }

    public function test_a_lost_webhook_is_put_back_when_checked_on_this_apis_own_address(): void
    {
        // Found live 2026-10-13: Telegram had no address at all, so the bot answered nobody.
        $own = 'https://bookly-api.example.com/api/v1/telegram/webhook';
        Http::fake([
            self::URL => Http::sequence()
                ->push(['ok' => true, 'result' => ['url' => '']])     // health check
                ->push(['ok' => true, 'result' => ['url' => '']])     // ensureWebhook() looks again
                ->push(['ok' => true, 'result' => ['url' => $own]]),  // next health check
            'api.telegram.org/bot'.self::TOKEN.'/setWebhook' => Http::response(['ok' => true, 'result' => true]),
        ]);

        $this->getJson('https://bookly-api.example.com/api/v1/health')->assertOk()
            ->assertJsonPath('checks.telegram_bot', ['status' => 'degraded', 'error' => 'webhook not set', 'repaired' => true]);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/setWebhook') && $request['url'] === $own
            && $request['secret_token'] === app(TelegramBot::class)->webhookSecret());

        $this->getJson('https://bookly-api.example.com/api/v1/health')->assertJsonPath('checks.telegram_bot', ['status' => 'ok']);
    }

    public function test_checks_on_another_host_name_never_change_the_webhook(): void
    {
        // Render's own health checks arrive on an internal address; the bot must never be pointed there.
        $this->webhookInfo(['url' => '']);

        $this->getJson('http://10.0.0.5:10000/api/v1/health')->assertOk()
            ->assertJsonPath('checks.telegram_bot', ['status' => 'degraded', 'error' => 'webhook not set']);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/setWebhook'));
    }

    public function test_a_faked_forwarded_host_cannot_point_the_bot_elsewhere(): void
    {
        Http::fake([
            self::URL => Http::response(['ok' => true, 'result' => ['url' => '']]),
            'api.telegram.org/bot'.self::TOKEN.'/setWebhook' => Http::response(['ok' => true, 'result' => true]),
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
            ->getJson('https://bookly-api.example.com/api/v1/health', ['X-Forwarded-Host' => 'evil.example.com', 'X-Forwarded-Port' => '8443'])
            ->assertJsonPath('checks.telegram_bot.repaired', true);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/setWebhook')
            && $request['url'] === 'https://bookly-api.example.com/api/v1/telegram/webhook');
        Http::assertNotSent(fn ($request) => str_contains(json_encode($request->data()), 'evil.example.com'));
    }

    public function test_database_down_keeps_the_existing_response_and_does_not_call_telegram(): void
    {
        Http::fake();
        DB::partialMock()->shouldReceive('select')->with('select 1')->once()->andThrow(new RuntimeException('Database down'));

        $this->getJson('/api/v1/health')->assertStatus(503)
            ->assertExactJson(['status' => 'down', 'checks' => ['database' => ['status' => 'down']]]);
        Http::assertNothingSent();
    }
}
