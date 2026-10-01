<?php

namespace Tests\Feature\Auth;

use App\Enums\StaffRole;
use App\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_an_admin_with_a_hashed_password(): void
    {
        $this->artisan('staff:create-admin', ['--name' => 'Owner', '--email' => 'Owner@Shop.test'])
            ->expectsQuestion('Password (min 8 characters, letters and numbers)', 'secret123')
            ->expectsQuestion('Confirm password', 'secret123')
            ->assertSuccessful();

        $admin = StaffUser::firstWhere('email', 'owner@shop.test');
        $this->assertSame(StaffRole::Admin, $admin->role);
        $this->assertFalse($admin->two_factor_enabled);
        $this->assertTrue(Hash::check('secret123', $admin->password_hash));
    }

    public function test_rejects_weak_or_mismatched_passwords(): void
    {
        $this->artisan('staff:create-admin', ['--name' => 'Owner', '--email' => 'owner@shop.test'])
            ->expectsQuestion('Password (min 8 characters, letters and numbers)', 'secret123')
            ->expectsQuestion('Confirm password', 'different1')
            ->assertFailed();

        $this->artisan('staff:create-admin', ['--name' => 'Owner', '--email' => 'owner@shop.test'])
            ->expectsQuestion('Password (min 8 characters, letters and numbers)', 'short')
            ->expectsQuestion('Confirm password', 'short')
            ->assertFailed();

        $this->assertSame(0, StaffUser::count());
    }
}
