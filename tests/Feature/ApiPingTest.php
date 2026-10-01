<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiPingTest extends TestCase
{
    public function test_versioned_ping_endpoint_responds(): void
    {
        $this->getJson('/api/v1/ping')
            ->assertOk()
            ->assertExactJson(['status' => 'ok', 'version' => 'v1']);
    }
}
