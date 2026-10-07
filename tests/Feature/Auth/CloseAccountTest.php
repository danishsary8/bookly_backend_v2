<?php

namespace Tests\Feature\Auth;

use App\Enums\OrderStatus;
use App\Models\Book;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Customers\ClosedCustomers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class CloseAccountTest extends TestCase
{
    use RefreshDatabase;

    private function close(Customer $customer, array $body)
    {
        $token = $customer->createToken('t', ['customer'])->plainTextToken;

        return $this->withToken($token)->deleteJson('/api/v1/me', $body);
    }

    public function test_a_customer_closes_their_account_with_their_password(): void
    {
        $customer = Customer::factory()->create(['email' => 'sok@example.com', 'password_hash' => 'reading123']);
        $book = Book::factory()->create();
        $customer->wishlistBooks()->attach($book->id);
        $item = OrderItem::factory()->for(Order::factory()->for($customer)->create(['status' => OrderStatus::Delivered]))->create();
        DB::table('reviews')->insert(['customer_id' => $customer->id, 'book_id' => $item->variant->book_id, 'order_item_id' => $item->id, 'rating' => 5, 'created_at' => now(), 'updated_at' => now()]);

        $this->close($customer, ['password' => 'wrong', 'confirm' => 'DELETE'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->close($customer, ['password' => 'reading123', 'confirm' => 'yes'])->assertUnprocessable()->assertJsonValidationErrors(['confirm' => 'Type DELETE']);

        $this->close($customer, ['password' => 'reading123', 'confirm' => 'DELETE'])->assertOk()
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'We erase your personal details on'));

        $this->assertSoftDeleted($customer);
        $this->assertSame(0, $customer->tokens()->count());
        $this->assertDatabaseCount('reviews', 0);
        $this->assertDatabaseCount('wishlists', 0);
        $this->postJson('/api/v1/auth/login', ['email' => 'sok@example.com', 'password' => 'reading123'])->assertUnauthorized();
        // The email stays reserved until the details are erased.
        $this->postJson('/api/v1/auth/register', ['name' => 'X', 'email' => 'sok@example.com', 'password' => 'reading123', 'password_confirmation' => 'reading123'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_open_orders_must_finish_first(): void
    {
        $customer = Customer::factory()->create(['password_hash' => 'reading123']);
        Order::factory()->for($customer)->create(['status' => OrderStatus::Shipped]);

        $this->close($customer, ['password' => 'reading123', 'confirm' => 'DELETE'])->assertUnprocessable()
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'order on its way'));
        $this->assertNotSoftDeleted($customer);
    }

    public function test_accounts_without_a_password_confirm_with_their_provider(): void
    {
        config(['services.google.client_id' => 'bookly-google']);
        Http::fake(['oauth2.googleapis.com/tokeninfo*' => Http::response(['aud' => 'bookly-google'])]);
        $driver = Mockery::mock();
        $driver->shouldReceive('stateless')->andReturnSelf();
        $driver->shouldReceive('userFromToken')->andReturn((new SocialiteUser)->map(['id' => 'g-1']), (new SocialiteUser)->map(['id' => 'g-2']));
        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);
        $customer = Customer::factory()->create(['password_hash' => null, 'google_id' => 'g-2']);

        $this->close($customer, ['confirm' => 'DELETE'])->assertUnprocessable()->assertJsonValidationErrors(['provider', 'access_token']);
        $this->close($customer, ['confirm' => 'DELETE', 'provider' => 'google', 'access_token' => 'someone-else'])->assertUnprocessable();
        $this->close($customer, ['confirm' => 'DELETE', 'provider' => 'google', 'access_token' => 'theirs'])->assertOk();
        $this->assertSoftDeleted($customer);
    }

    public function test_personal_details_are_erased_after_30_days_and_orders_are_kept(): void
    {
        $customer = Customer::factory()->create(['email' => 'sok@example.com', 'phone_e164' => '+85512345678', 'phone_verified_at' => now(), 'password_hash' => 'reading123']);
        CustomerAddress::factory()->for($customer)->create();
        $order = Order::factory()->for($customer)->create(['status' => OrderStatus::Delivered]);
        app(ClosedCustomers::class)->close($customer);

        $this->artisan('customers:erase-closed')->assertSuccessful();
        $this->assertSame('sok@example.com', Customer::withTrashed()->find($customer->id)->email, 'not before 30 days');

        $this->travel(31)->days();
        $this->artisan('customers:erase-closed')->assertSuccessful();

        $erased = Customer::withTrashed()->find($customer->id);
        $this->assertSame(ClosedCustomers::ERASED_NAME, $erased->name);
        $this->assertNull($erased->email);
        $this->assertNull($erased->phone_e164);
        $this->assertNull($erased->password_hash);
        $this->assertSame(0, $erased->addresses()->count());
        $this->assertNotNull($order->fresh(), 'sales records stay');

        // The email and number are free again.
        $this->postJson('/api/v1/auth/register', ['name' => 'Sok', 'email' => 'sok@example.com', 'password' => 'reading123', 'password_confirmation' => 'reading123'])->assertCreated();
    }
}
