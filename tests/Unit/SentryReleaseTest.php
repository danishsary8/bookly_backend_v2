<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SentryReleaseTest extends TestCase
{
    #[DataProvider('releases')]
    public function test_resolves_release_from_environment(?string $release, ?string $commit, ?string $expected): void
    {
        $originalEnv = $_ENV;
        $originalServer = $_SERVER;
        $originalValues = [];

        foreach (['SENTRY_RELEASE', 'RENDER_GIT_COMMIT'] as $name) {
            $originalValues[$name] = getenv($name);
        }

        try {
            foreach (['SENTRY_RELEASE' => $release, 'RENDER_GIT_COMMIT' => $commit] as $name => $value) {
                unset($_ENV[$name], $_SERVER[$name]);
                putenv($value === null ? $name : "$name=$value");

                if ($value !== null) {
                    $_ENV[$name] = $value;
                    $_SERVER[$name] = $value;
                }
            }

            $config = require __DIR__.'/../../config/sentry.php';

            $this->assertSame($expected, $config['release']);
        } finally {
            $_ENV = $originalEnv;
            $_SERVER = $originalServer;

            foreach ($originalValues as $name => $value) {
                putenv($value === false ? $name : "$name=$value");
            }
        }
    }

    public static function releases(): array
    {
        return [
            'explicit release overrides Render' => ['bookly-v1', 'abc123', 'bookly-v1'],
            'explicit release without Render' => ['bookly-v1', null, 'bookly-v1'],
            'Render fallback' => [null, 'abc123', 'abc123'],
            'empty release uses Render' => ['', 'abc123', 'abc123'],
            'neither set' => [null, null, null],
        ];
    }
}
