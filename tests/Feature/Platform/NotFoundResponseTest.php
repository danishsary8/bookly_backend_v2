<?php

namespace Tests\Feature\Platform;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A missing record answers a plain 404, never the internal model class name (e.g. App\Models\Order). */
class NotFoundResponseTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_missing_record_does_not_reveal_the_model_class(): void
    {
        config(['app.debug' => false]);

        $this->getJson('/api/v1/books/999999')->assertNotFound()->assertExactJson(['message' => 'Not found.']);

        $customer = Customer::factory()->create();
        $this->withToken($customer->createToken('t', ['customer'])->plainTextToken)
            ->getJson('/api/v1/orders/999999')->assertNotFound()->assertExactJson(['message' => 'Not found.']);
    }

    public function test_an_unknown_route_still_answers_json_404(): void
    {
        $this->getJson('/api/v1/no-such-route')->assertNotFound()->assertJsonStructure(['message']);
    }
}
