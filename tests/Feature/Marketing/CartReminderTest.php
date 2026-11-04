<?php

namespace Tests\Feature\Marketing;

use App\Models\Book;
use App\Models\BookVariant;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\MarketingOutbox;
use App\Notifications\CartReminderNotification;
use App\Services\Marketing\CartReminders;
use App\Services\Marketing\MarketingOutboxService;
use App\Support\MaintenanceTasks;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Owner 2026-11-05: one friendly email 24 h after a signed-in customer leaves books in the cart. */
class CartReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Notification::fake();
    }

    /** A customer whose cart was last touched $hoursAgo hours ago. */
    private function cartLeft(int $hoursAgo, array $customer = [], int $books = 1): Customer
    {
        $c = Customer::factory()->create(['email_verified_at' => now(), ...$customer]);
        $this->travel(-$hoursAgo)->hours();
        $cart = Cart::create(['customer_id' => $c->id]);
        foreach (range(1, $books) as $i) {
            $variant = BookVariant::factory()->create(['book_id' => Book::factory()->create(['title' => "Book {$i}"])->id]);
            $cart->items()->create(['book_variant_id' => $variant->id, 'quantity' => 1, 'unit_price_at_add' => $variant->price_usd]);
        }
        $this->travelBack();

        return $c;
    }

    private function queue(): int
    {
        return app(CartReminders::class)->queueDue();
    }

    public function test_a_cart_left_for_a_day_gets_one_email_with_the_books_and_a_stop_link(): void
    {
        $customer = $this->cartLeft(25, ['name' => 'Dara'], books: 5);
        $this->cartLeft(3); // too soon

        $this->assertSame(1, $this->queue());
        $this->assertSame(0, $this->queue()); // not twice

        app(MarketingOutboxService::class)->sendPending();

        Notification::assertSentTo(new AnonymousNotifiable, CartReminderNotification::class, function (CartReminderNotification $n, $channels, $notifiable) use ($customer) {
            $mail = $n->toMail($notifiable);
            $text = implode("\n", [...$mail->introLines, ...$mail->outroLines]);

            return $notifiable->routes['mail'] === $customer->email
                && $mail->greeting === 'Hi Dara,'
                && str_contains($text, '• Book 1') && str_contains($text, '• Book 3') && ! str_contains($text, 'Book 4')
                && str_contains($text, '…and 2 more books')
                && str_ends_with($mail->actionUrl, '/cart')
                && str_contains($text, '/email/cart-reminders/stop?customer='.$customer->id.'&token='.CartReminders::stopToken($customer));
        });
        $this->assertSame('sent', MarketingOutbox::where('kind', CartReminders::KIND)->value('status'));
    }

    public function test_who_does_not_get_one(): void
    {
        $this->cartLeft(25, ['cart_reminders' => false]);
        $this->cartLeft(25, ['email_verified_at' => null]);
        $this->cartLeft(25, ['is_active' => false]);
        $this->cartLeft(25, ['email' => DemoSeeder::EMAIL]);
        $this->cartLeft(24 * 9); // left too long ago to be helpful
        Cart::create(['customer_id' => Customer::factory()->create(['email_verified_at' => now()])->id]); // empty cart

        $this->assertSame(0, $this->queue());
    }

    public function test_coming_back_or_checking_out_before_it_goes_cancels_it(): void
    {
        $back = $this->cartLeft(25);
        $done = $this->cartLeft(25);
        $off = $this->cartLeft(25);
        $this->assertSame(3, $this->queue());

        $this->travel(5)->minutes();
        $item = Cart::where('customer_id', $back->id)->first()->items()->first();
        $item->update(['quantity' => 2]);
        Cart::where('customer_id', $done->id)->first()->items()->delete();
        $off->update(['cart_reminders' => false]);

        $this->assertSame(0, app(MarketingOutboxService::class)->sendPending());
        Notification::assertSentTimes(CartReminderNotification::class, 0);
        $this->assertSame(3, MarketingOutbox::where('status', 'cancelled')->count());
    }

    public function test_at_most_one_a_week_and_only_after_the_cart_changes(): void
    {
        $customer = $this->cartLeft(25);
        $this->assertSame(1, $this->queue());

        // Changed 2 days later, left again for a day: still inside the week, so nothing.
        $this->travel(2)->days();
        Cart::where('customer_id', $customer->id)->first()->items()->first()->update(['quantity' => 3]);
        $this->travel(25)->hours();
        $this->assertSame(0, $this->queue());

        // After the week: the newer change earns one more.
        $this->travel(5)->days();
        Cart::where('customer_id', $customer->id)->first()->items()->first()->update(['quantity' => 4]);
        $this->travel(25)->hours();
        $this->assertSame(1, $this->queue());

        // Without a new change, never again.
        $this->travel(10)->days();
        $this->assertSame(0, $this->queue());
    }

    public function test_removing_a_line_counts_as_cart_activity(): void
    {
        $customer = $this->cartLeft(25, books: 2);
        Cart::where('customer_id', $customer->id)->first()->items()->first()->delete();

        $this->assertSame(0, $this->queue());
    }

    public function test_the_stop_link_turns_them_off_without_signing_in(): void
    {
        $customer = $this->cartLeft(25);
        $token = CartReminders::stopToken($customer);

        $this->postJson('/api/v1/cart-reminders/stop', ['customer' => $customer->id, 'token' => 'wrong'])->assertUnprocessable();
        $this->postJson('/api/v1/cart-reminders/stop', ['customer' => $customer->id + 1, 'token' => $token])->assertUnprocessable();
        $this->assertTrue($customer->fresh()->cart_reminders);

        $this->postJson('/api/v1/cart-reminders/stop', ['customer' => $customer->id, 'token' => $token])->assertOk();
        $this->assertFalse($customer->fresh()->cart_reminders);
        $this->assertSame(0, $this->queue());

        $customer->update(['cart_reminders' => true]);
        $this->post("/api/v1/cart-reminders/stop/one-click?customer={$customer->id}&token={$token}", ['List-Unsubscribe' => 'One-Click'])->assertOk();
        $this->assertFalse($customer->fresh()->cart_reminders);
    }

    public function test_the_profile_switch_and_the_schedule(): void
    {
        $customer = Customer::factory()->create(['email_verified_at' => now()]);
        Sanctum::actingAs($customer, ['customer']);

        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.cart_reminders', true);
        $this->patchJson('/api/v1/me', ['cart_reminders' => false])->assertOk()->assertJsonPath('data.cart_reminders', false);

        $this->assertContains(['name' => 'cart-reminders', 'command' => 'cart:remind', 'parameters' => [], 'cron' => '5 * * * *'], MaintenanceTasks::all());
        $this->artisan('cart:remind')->expectsOutput('Cart reminders queued: 0.')->assertSuccessful();
    }

    public function test_khmer_customers_get_it_in_their_language_setting(): void
    {
        $this->cartLeft(25, ['locale' => 'km']);
        $this->queue();

        $this->assertSame('km', MarketingOutbox::where('kind', CartReminders::KIND)->value('locale'));
    }
}
