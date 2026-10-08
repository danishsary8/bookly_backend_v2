<?php

namespace Tests\Feature\Platform;

use Illuminate\Support\Env;
use Tests\TestCase;

class PreviewCorsTest extends TestCase
{
    public function test_configured_patterns_allow_only_matching_preview_origins(): void
    {
        $this->loadCorsConfig(' , ^https://bookly-frontend-[a-z0-9-]+-danishsary8s-projects\.vercel\.app$ , , ^https://preview\.example$ , ');

        $this->assertSame([
            '~^https://bookly-frontend-[a-z0-9-]+-danishsary8s-projects\.vercel\.app$~',
            '~^https://preview\.example$~',
        ], config('cors.allowed_origins_patterns'));

        foreach (['https://bookly-frontend-abc123-danishsary8s-projects.vercel.app', 'https://preview.example'] as $origin) {
            $this->getJson('/api/v1/ping', ['Origin' => $origin])
                ->assertOk()
                ->assertHeader('Access-Control-Allow-Origin', $origin);
        }

        foreach (['https://evil.com', 'https://bookly-frontend-evil.vercel.app'] as $origin) {
            $this->getJson('/api/v1/ping', ['Origin' => $origin])
                ->assertOk()
                ->assertHeaderMissing('Access-Control-Allow-Origin');
        }
    }

    public function test_empty_patterns_preserve_the_exact_origin_list(): void
    {
        $this->loadCorsConfig('');

        $this->assertSame([], config('cors.allowed_origins_patterns'));

        $this->getJson('/api/v1/ping', ['Origin' => 'https://bookly.example'])
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'https://bookly.example');

        foreach (['https://bookly-frontend-abc123-danishsary8s-projects.vercel.app', 'https://evil.com', 'https://bookly-frontend-evil.vercel.app'] as $origin) {
            $this->getJson('/api/v1/ping', ['Origin' => $origin])
                ->assertOk()
                ->assertHeaderMissing('Access-Control-Allow-Origin');
        }
    }

    private function loadCorsConfig(string $patterns): void
    {
        $repository = Env::getRepository();
        $originalOrigins = $repository->get('CORS_ALLOWED_ORIGINS');
        $originalPatterns = $repository->get('CORS_ALLOWED_ORIGIN_PATTERNS');

        try {
            $repository->set('CORS_ALLOWED_ORIGINS', 'https://bookly.example,http://localhost:5173');
            $repository->set('CORS_ALLOWED_ORIGIN_PATTERNS', $patterns);
            config(['cors' => require base_path('config/cors.php')]);
        } finally {
            $originalOrigins === null
                ? $repository->clear('CORS_ALLOWED_ORIGINS')
                : $repository->set('CORS_ALLOWED_ORIGINS', $originalOrigins);
            $originalPatterns === null
                ? $repository->clear('CORS_ALLOWED_ORIGIN_PATTERNS')
                : $repository->set('CORS_ALLOWED_ORIGIN_PATTERNS', $originalPatterns);
        }
    }
}
