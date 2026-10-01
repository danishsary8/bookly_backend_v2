<?php

namespace Tests\Feature\Platform;

use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_api_responses_carry_security_headers(): void
    {
        $this->getJson('/api/v1/ping')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'")
            ->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_hsts_is_sent_over_https_only(): void
    {
        $this->getJson('https://localhost/api/v1/ping')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_docs_page_is_not_blocked_by_the_api_policy(): void
    {
        $this->get('/docs')->assertOk()->assertHeaderMissing('Content-Security-Policy')->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_cors_allows_only_the_configured_frontend_and_exposes_useful_headers(): void
    {
        $this->getJson('/api/v1/ping', ['Origin' => 'http://localhost:5173'])
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
            ->assertHeader('Access-Control-Expose-Headers');
        $this->assertStringContainsString('X-Request-Id', $this->getJson('/api/v1/ping', ['Origin' => 'http://localhost:5173'])->headers->get('Access-Control-Expose-Headers'));

        $this->getJson('/api/v1/ping', ['Origin' => 'https://evil.example'])
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }
}
