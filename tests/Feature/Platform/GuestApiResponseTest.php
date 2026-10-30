<?php

namespace Tests\Feature\Platform;

use Tests\TestCase;

class GuestApiResponseTest extends TestCase
{
    public function test_guest_browser_request_to_protected_api_route_returns_json_401(): void
    {
        // A plain browser visit (no "Accept: application/json") used to try redirecting to a missing "login" route.
        $this->get('/api/v1/staff/books', ['Accept' => 'text/html'])
            ->assertStatus(401)
            ->assertJson(['message' => 'Unauthenticated.']);
    }
}
