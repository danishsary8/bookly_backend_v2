<?php

namespace Tests\Feature\Auth;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class VerifiedCustomerAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Stand-in for shopping routes (cart, checkout) that use the same middleware stack.
        Route::middleware(['api', 'auth:sanctum', 'abilities:customer', 'verified.customer'])
            ->get('/api/v1/__test/shop', fn () => response()->json(['ok' => true]));
    }

    public function test_unverified_customer_is_blocked_from_shopping(): void
    {
        $token = Customer::factory()->unverified()->create()->createToken('t', ['customer'])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/__test/shop')
            ->assertForbidden()
            ->assertJsonPath('message', 'Please verify your email address first.');
    }

    public function test_verified_customer_can_shop(): void
    {
        $token = Customer::factory()->create()->createToken('t', ['customer'])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/__test/shop')->assertOk();
    }

    public function test_guest_gets_401(): void
    {
        $this->getJson('/api/v1/__test/shop')->assertUnauthorized();
    }
}
