<?php

namespace Tests\Feature\Platform;

use App\Enums\OrderStatus;
use App\Models\Book;
use App\Models\Customer;
use App\Models\Order;
use App\Models\StaffUser;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_a_working_demo_shop_and_runs_only_once(): void
    {
        $this->seed(DemoSeeder::class);

        $this->assertSame(20, Book::count());
        $this->assertSame(13, Order::count());
        $this->assertSame(8, Order::where('status', OrderStatus::Delivered)->count());
        $this->assertSame(2, StaffUser::count());
        $this->assertSame(5, Customer::count());

        // Orders went through the real checkout: stock was taken and the history is spread over the month.
        $this->assertDatabaseCount('inventory_movements', 21);
        $this->assertTrue(Order::min('placed_at') < now()->subDays(25));
        $this->assertSame(1, Order::where('status', OrderStatus::Cancelled)->count());

        // The demo accounts can log in.
        $this->postJson('/api/v1/auth/login', ['email' => 'demo@bookly.test', 'password' => DemoSeeder::PASSWORD])->assertOk();
        $this->postJson('/api/v1/staff/auth/login', ['email' => 'admin@bookly.test', 'password' => DemoSeeder::PASSWORD])->assertOk();

        // Running it again changes nothing.
        $this->seed(DemoSeeder::class);
        $this->assertSame(13, Order::count());
        $this->assertSame(20, Book::count());
    }

    public function test_production_refuses_unless_explicitly_allowed_and_then_hides_staff_passwords(): void
    {
        $this->app['env'] = 'production';

        $seed = fn () => $this->artisan('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);

        $seed()->expectsOutputToContain('does not run in production')->assertSuccessful();
        $this->assertSame(0, Book::count(), 'refused without DEMO_SEED_ALLOWED');

        config(['app.demo_seed_allowed' => true]);
        $seed()->expectsOutputToContain('shown once, save it now')->assertSuccessful();
        $this->assertSame(20, Book::count());

        // The published password works for the demo customer only, never for staff.
        $this->postJson('/api/v1/auth/login', ['email' => 'demo@bookly.test', 'password' => DemoSeeder::PASSWORD])->assertOk();
        $this->postJson('/api/v1/staff/auth/login', ['email' => 'admin@bookly.test', 'password' => DemoSeeder::PASSWORD])->assertUnauthorized();
    }
}
