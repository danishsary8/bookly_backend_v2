<?php

namespace Tests\Feature\Platform;

use App\Models\StaffUser;
use App\Services\Telegram\TelegramBot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class TelegramHealthTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = '123:health-test-token';

    private const URL = 'https://api.telegram.org/bot'.self::TOKEN.'/getWebhookInfo';

    private const OWN = 'https://bookly-api.example.com/api/v1/telegram/webhook';

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

    private function adminToken(): string
    {
        return StaffUser::factory()->admin()->withTwoFactor()->create()->createToken('t', ['staff', 'admin'])->plainTextToken;
    }

    private function webhookInfo(array $info = []): void
    {
        Http::fake([self::URL => Http::response([
            'ok' => true,
            'result' => array_replace(['url' => self::OWN], $info),
        ])]);
    }

    public function test_bot_is_off_without_a_token_and_no_request_is_sent(): void
    {
        config(['services.telegram_bot.token' => null]);
        Http::fake();

        // Public health is ok with no checks
        $this->getJson('/api/v1/health')->assertOk()->assertExactJson(['status' => 'ok']);

        // Admin health shows off
        $this->withToken($this->adminToken())->getJson('/api/v1/staff/health')->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.telegram_bot', ['status' => 'off']);
        Http::assertNothingSent();
    }

    public function test_matching_webhook_is_ok(): void
    {
        $this->webhookInfo();

        $this->getJson('/api/v1/health')->assertOk()->assertExactJson(['status' => 'ok']);

        $response = $this->withToken($this->adminToken())->getJson('/api/v1/staff/health')->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.telegram_bot', ['status' => 'ok']);
        $this->assertStringNotContainsString(self::TOKEN, $response->getContent());
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->url() === self::URL);
        Http::assertSentCount(1);
    }

    public function test_webhook_is_compared_with_app_url_whatever_host_the_request_came_in_on(): void
    {
        // Behind Render's proxy the app sees an internal host name, not the public one.
        $this->webhookInfo();

        $this->withToken($this->adminToken())->getJson('http://10.0.0.5:10000/api/v1/staff/health')->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.telegram_bot', ['status' => 'ok']);
        Http::assertSentCount(1);
    }

    public function test_missing_webhook_degrades_health(): void
    {
        $this->webhookInfo(['url' => '']);

        $this->getJson('/api/v1/health')->assertOk()->assertExactJson(['status' => 'ok']);

        $this->withToken($this->adminToken())->getJson('/api/v1/staff/health')->assertOk()
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.telegram_bot.status', 'degraded')
            ->assertJsonPath('checks.telegram_bot.error', 'webhook not set');
    }

    public function test_wrong_webhook_degrades_health_without_exposing_its_url(): void
    {
        $this->webhookInfo(['url' => 'https://wrong.example.com/'.self::TOKEN]);

        $response = $this->withToken($this->adminToken())->getJson('/api/v1/staff/health')->assertOk()
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.telegram_bot.error', 'webhook not set');
        $this->assertStringNotContainsString(self::TOKEN, $response->getContent());
    }

    public function test_recent_delivery_error_degrades_health(): void
    {
        $this->webhookInfo(['last_error_date' => now()->subMinutes(29)->timestamp, 'last_error_message' => 'Wrong response: 404 Not Found']);

        $this->withToken($this->adminToken())->getJson('/api/v1/staff/health')->assertOk()
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.telegram_bot', ['status' => 'degraded', 'error' => 'Wrong response: 404 Not Found']);
    }

    public function test_delivery_errors_older_than_thirty_minutes_are_ignored_even_while_cached(): void
    {
        $this->webhookInfo(['last_error_date' => now()->subMinutes(29)->timestamp, 'last_error_message' => 'Timeout']);
        $this->withToken($this->adminToken())->getJson('/api/v1/staff/health')->assertJsonPath('status', 'degraded');

        $this->travel(2)->minutes();

        $this->withToken($this->adminToken())->getJson('/api/v1/staff/health')->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.telegram_bot', ['status' => 'ok']);
        Http::assertSentCount(1);
    }

    public function test_an_undated_error_is_ignored(): void
    {
        $this->webhookInfo(['last_error_message' => 'Old timeout']);

        $this->withToken($this->adminToken())->getJson('/api/v1/staff/health')->assertOk()->assertJsonPath('checks.telegram_bot.status', 'ok');
    }

    public function test_unreachable_telegram_degrades_health_and_caches_the_failure(): void
    {
        Http::fake([self::URL => Http::failedConnection('Cannot reach '.self::URL)]);

        foreach ([0, 5] as $minutes) {
            $this->travel($minutes)->minutes();
            $response = $this->withToken($this->adminToken())->getJson('/api/v1/staff/health')->assertOk()
                ->assertJsonPath('status', 'degraded')
                ->assertJsonPath('checks.telegram_bot', ['status' => 'degraded', 'error' => 'Telegram unreachable']);
            $this->assertStringNotContainsString(self::TOKEN, $response->getContent());
        }
        Http::assertSentCount(1);
    }

    public function test_telegram_refusal_never_exposes_the_token(): void
    {
        Http::fake([self::URL => Http::response(['ok' => false, 'description' => 'Unauthorized '.self::TOKEN], 401)]);

        $response = $this->withToken($this->adminToken())->getJson('/api/v1/staff/health')->assertOk()
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.telegram_bot.error', 'Telegram unreachable');
        $this->assertStringNotContainsString(self::TOKEN, $response->getContent());
    }

    public function test_delivery_error_redacts_the_token(): void
    {
        $this->webhookInfo(['last_error_date' => now()->timestamp, 'last_error_message' => 'Failed for '.self::TOKEN]);

        $response = $this->withToken($this->adminToken())->getJson('/api/v1/staff/health')->assertOk()
            ->assertJsonPath('checks.telegram_bot.error', 'Failed for ***');
        $this->assertStringNotContainsString(self::TOKEN, $response->getContent());
    }

    public function test_webhook_info_is_cached_for_ten_minutes_then_refreshed(): void
    {
        Http::fake([self::URL => Http::sequence()
            ->push(['ok' => true, 'result' => ['url' => self::OWN]])
            ->push(['ok' => true, 'result' => ['url' => '']])]);

        $this->withToken($this->adminToken())->getJson('/api/v1/staff/health')->assertJsonPath('checks.telegram_bot.status', 'ok');
        $this->travel(5)->minutes();
        $this->withToken($this->adminToken())->getJson('/api/v1/staff/health')->assertJsonPath('checks.telegram_bot.status', 'ok');
        Http::assertSentCount(1);

        $this->travel(5)->minutes();
        $this->withToken($this->adminToken())->getJson('/api/v1/staff/health')->assertJsonPath('checks.telegram_bot.error', 'webhook not set');
        Http::assertSentCount(2);
    }

    public function test_a_lost_webhook_is_put_back_on_app_url_even_when_checked_on_an_internal_host(): void
    {
        // Found live 2026-10-13: Telegram had no address at all, so the bot answered nobody.
        Http::fake([
            self::URL => Http::sequence()
                ->push(['ok' => true, 'result' => ['url' => '']])         // health check
                ->push(['ok' => true, 'result' => ['url' => '']])         // ensureWebhook() looks again
                ->push(['ok' => true, 'result' => ['url' => self::OWN]]), // next health check
            'api.telegram.org/bot'.self::TOKEN.'/setWebhook' => Http::response(['ok' => true, 'result' => true]),
        ]);

        // Public check triggers the repair on uptime monitor ping
        $this->getJson('http://10.0.0.5:10000/api/v1/health')->assertOk()
            ->assertExactJson(['status' => 'ok']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/setWebhook') && $request['url'] === self::OWN
            && $request['secret_token'] === app(TelegramBot::class)->webhookSecret());

        $this->withToken($this->adminToken())->getJson('http://10.0.0.5:10000/api/v1/staff/health')->assertJsonPath('checks.telegram_bot', ['status' => 'ok']);
    }

    public function test_a_faked_forwarded_host_cannot_point_the_bot_elsewhere(): void
    {
        Http::fake([
            self::URL => Http::response(['ok' => true, 'result' => ['url' => '']]),
            'api.telegram.org/bot'.self::TOKEN.'/setWebhook' => Http::response(['ok' => true, 'result' => true]),
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
            ->withToken($this->adminToken())
            ->getJson('https://bookly-api.example.com/api/v1/staff/health', ['X-Forwarded-Host' => 'evil.example.com', 'X-Forwarded-Port' => '8443'])
            ->assertJsonPath('checks.telegram_bot.repaired', true);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/setWebhook') && $request['url'] === self::OWN);
        Http::assertNotSent(fn ($request) => str_contains(json_encode($request->data()), 'evil.example.com'));
    }

    public function test_repair_is_tried_at_most_once_per_ten_minutes(): void
    {
        // Render checks every few seconds; a Telegram outage must not flood Sentry.
        Exceptions::fake();
        Http::fake([
            self::URL => Http::response(['ok' => true, 'result' => ['url' => '']]),
            'api.telegram.org/bot'.self::TOKEN.'/setWebhook' => Http::response(['ok' => false, 'description' => 'Too Many Requests'], 429),
        ]);

        $this->withToken($this->adminToken())->getJson('/api/v1/staff/health')->assertJsonPath('checks.telegram_bot.repaired', false);
        $this->travel(5)->seconds();
        $this->withToken($this->adminToken())->getJson('/api/v1/staff/health')->assertJsonMissingPath('checks.telegram_bot.repaired');

        Http::assertSentCount(3); // getWebhookInfo (health), getWebhookInfo + setWebhook (one repair)
        Exceptions::assertReportedCount(1);
    }

    public function test_database_down_keeps_the_existing_response_and_does_not_call_telegram(): void
    {
        Http::fake();
        DB::partialMock()->shouldReceive('select')->with('select 1')->once()->andThrow(new RuntimeException('Database down'));

        // Public health returns 503 fail
        $this->getJson('/api/v1/health')->assertStatus(503)
            ->assertExactJson(['status' => 'fail']);
        Http::assertNothingSent();
    }
}
