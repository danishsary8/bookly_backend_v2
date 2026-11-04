<?php

namespace Tests;

use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Tests use simple passwords ("Secret123") that real leak lists contain, and must never call the internet:
        // the leaked-password check (PasswordRules) answers "not leaked" here. LeakedPasswordTest checks the real one.
        $this->app->instance(UncompromisedVerifier::class, new class implements UncompromisedVerifier
        {
            public function verify($data)
            {
                return true;
            }
        });
    }
}
