<?php

namespace Tests\Feature\Auth;

use App\Models\Customer;
use App\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CustomerProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_view_and_update_profile(): void
    {
        $customer = Customer::factory()->create(['name' => 'Old']);
        $token = $customer->createToken('t', ['customer'])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.email', $customer->email);
        $this->withToken($token)->patchJson('/api/v1/me', ['name' => 'New Name', 'phone' => '012999888'])
            ->assertOk()->assertJsonPath('data.name', 'New Name');
    }

    public function test_change_password_requires_current_password_and_signs_out_other_sessions(): void
    {
        $customer = Customer::factory()->create(['password_hash' => 'oldpass123']);
        $current = $customer->createToken('current', ['customer'])->plainTextToken;
        $customer->createToken('other-device', ['customer']);

        $this->withToken($current)->putJson('/api/v1/me/password', [
            'current_password' => 'wrongpass1', 'password' => 'newpass123', 'password_confirmation' => 'newpass123',
        ])->assertUnprocessable();

        $this->withToken($current)->putJson('/api/v1/me/password', [
            'current_password' => 'oldpass123', 'password' => 'newpass123', 'password_confirmation' => 'newpass123',
        ])->assertOk();

        $this->assertTrue(Hash::check('newpass123', $customer->fresh()->password_hash));
        $this->assertSame(1, $customer->tokens()->count());
    }

    public function test_social_only_customer_can_set_a_first_password(): void
    {
        $customer = Customer::factory()->create(['password_hash' => null, 'google_id' => 'g-1']);
        $token = $customer->createToken('t', ['customer'])->plainTextToken;

        $this->withToken($token)->putJson('/api/v1/me/password', [
            'password' => 'newpass123', 'password_confirmation' => 'newpass123',
        ])->assertOk();
    }

    public function test_staff_token_cannot_use_customer_routes(): void
    {
        $token = StaffUser::factory()->create()->createToken('t', ['staff'])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/me')->assertForbidden();
    }
}
