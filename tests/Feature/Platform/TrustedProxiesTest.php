<?php

namespace Tests\Feature\Platform;

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class TrustedProxiesTest extends TestCase
{
    private function hit(): TestResponse
    {
        Route::middleware('api')->get('/api/v1/__test/ip', fn () => ['ip' => request()->ip(), 'secure' => request()->isSecure()]);

        // As it arrives on Railway: the edge (10.0.0.5) forwards a visitor who tried to fake their IP.
        return $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])->getJson('/api/v1/__test/ip', [
            'X-Forwarded-For' => '6.6.6.6, 203.0.113.7',
            'X-Forwarded-Proto' => 'https',
        ]);
    }

    public function test_behind_the_host_proxy_the_real_visitor_ip_and_https_are_seen(): void
    {
        $this->hit()
            ->assertJson(['ip' => '203.0.113.7', 'secure' => true]) // the address the edge added, not the faked one
            ->assertHeader('Strict-Transport-Security');
    }

    public function test_with_no_trusted_proxies_forwarded_headers_are_ignored(): void
    {
        $this->refreshWithProxies('');

        $this->hit()->assertJson(['ip' => '10.0.0.5', 'secure' => false])->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_trusting_everyone_would_let_visitors_fake_their_ip(): void
    {
        // Why the default is REMOTE_ADDR and not '*': documents the risk.
        $this->refreshWithProxies('*');

        $this->hit()->assertJson(['ip' => '6.6.6.6']);
    }

    public function test_a_specific_proxy_list_is_supported(): void
    {
        $this->refreshWithProxies('192.168.1.1, 10.0.0.0/8');

        $this->hit()->assertJson(['ip' => '203.0.113.7']);
    }

    private function refreshWithProxies(string $value): void
    {
        config(['app.trusted_proxies' => $value]);
        (new AppServiceProvider($this->app))->boot();
    }
}
