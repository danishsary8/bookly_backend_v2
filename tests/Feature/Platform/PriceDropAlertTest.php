<?php

namespace Tests\Feature\Platform;

use App\Enums\BookFormat;
use App\Models\Book;
use App\Models\BookVariant;
use App\Models\Customer;
use App\Models\MarketingOutbox;
use App\Models\StaffUser;
use App\Models\Wishlist;
use App\Notifications\PriceDropNotification;
use App\Services\Marketing\MarketingOutboxService;
use App\Services\Marketing\PriceDropAlerts;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PriceDropAlertTest extends TestCase
{
    use RefreshDatabase;

    private Book $book;

    private BookVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Notification::fake();
        $this->book = Book::factory()->create(['title' => 'The Mekong', 'status' => 'live']);
        $this->variant = BookVariant::factory()->create([
            'book_id' => $this->book->id, 'format' => BookFormat::Paperback, 'price_usd' => '20.00', 'is_active' => true,
        ]);
    }

    private function fan(array $attributes = [], string $status = Wishlist::STATUS_WANT_TO_READ): Customer
    {
        $customer = Customer::factory()->create(['email_verified_at' => now(), 'price_drop_alerts' => true, ...$attributes]);
        $customer->wishlistBooks()->attach($this->book->id, ['status' => $status]);

        return $customer;
    }

    private function staffSetsPrice(string $price, ?StaffUser $staff = null): void
    {
        $staff ??= StaffUser::factory()->admin()->withTwoFactor()->create();
        $this->app['auth']->forgetGuards();
        $this->withToken($staff->createToken('t', $staff->tokenAbilities())->plainTextToken)
            ->patchJson("/api/v1/staff/variants/{$this->variant->id}", ['price_usd' => $price])
            ->assertOk();
    }

    public function test_a_price_cut_by_staff_queues_one_email_per_opted_in_wishlist_customer(): void
    {
        $fan = $this->fan(['locale' => 'km', 'name' => 'Sokha']);

        $this->staffSetsPrice('15.00');

        $row = MarketingOutbox::sole();
        $this->assertSame(PriceDropAlerts::KIND, $row->kind);
        $this->assertSame($fan->id, $row->customer_id);
        $this->assertSame('km', $row->locale);
        $this->assertSame('pending', $row->status);
        $this->assertSame(['book_id' => $this->book->id, 'book_title' => 'The Mekong', 'variant_id' => $this->variant->id, 'format' => 'paperback',
            'old_price_usd' => '20.00', 'new_price_usd' => '15.00', 'customer_name' => 'Sokha'], $row->payload);
    }

    public function test_only_customers_who_opted_in_and_want_to_read_it_are_emailed(): void
    {
        $this->fan(['price_drop_alerts' => false]);              // switch off (the default)
        $this->fan([], Wishlist::STATUS_READING);                  // already has it
        $this->fan([], Wishlist::STATUS_FINISHED);
        $this->fan(['email_verified_at' => null]);                 // email never confirmed
        $this->fan(['is_active' => false]);
        $this->fan(['email' => DemoSeeder::EMAIL]);                // shared demo account
        Customer::factory()->create(['email_verified_at' => now(), 'price_drop_alerts' => true]); // no wishlist
        $yes = $this->fan();

        $this->staffSetsPrice('10.00');

        $this->assertSame([$yes->id], MarketingOutbox::pluck('customer_id')->all());
    }

    public function test_a_new_customer_has_the_emails_off_and_can_turn_them_on_in_the_profile(): void
    {
        $customer = Customer::factory()->create(['email_verified_at' => now()]);
        Sanctum::actingAs($customer, ['customer']);

        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.price_drop_alerts', false);
        $this->patchJson('/api/v1/me', ['price_drop_alerts' => true])->assertOk()->assertJsonPath('data.price_drop_alerts', true);
        $this->assertTrue($customer->fresh()->price_drop_alerts);
        $this->patchJson('/api/v1/me', ['price_drop_alerts' => 'yes please'])->assertUnprocessable();
    }

    public function test_drops_under_5_percent_rises_and_hidden_books_send_nothing(): void
    {
        $this->fan();

        $this->staffSetsPrice('19.01');   // 4.95% off
        $this->staffSetsPrice('25.00');   // up
        $this->assertSame(0, MarketingOutbox::count());

        $this->book->update(['status' => 'draft']);
        $this->staffSetsPrice('10.00');
        $this->assertSame(0, MarketingOutbox::count());

        $this->assertTrue(PriceDropAlerts::isBigDrop(10.00, 9.50));   // exactly 5% counts
        $this->assertFalse(PriceDropAlerts::isBigDrop(10.00, 9.51));
    }

    public function test_at_most_one_email_per_book_per_week(): void
    {
        $this->fan();

        $this->staffSetsPrice('15.00');
        $this->staffSetsPrice('12.00');
        $this->assertSame(1, MarketingOutbox::count());

        $this->travel(8)->days();
        $this->staffSetsPrice('10.00');
        $this->assertSame(2, MarketingOutbox::count());
    }

    public function test_bulk_edits_and_csv_imports_count_too(): void
    {
        $this->fan();
        $admin = StaffUser::factory()->admin()->withTwoFactor()->create();
        $this->withToken($admin->createToken('t', $admin->tokenAbilities())->plainTextToken)
            ->postJson('/api/v1/staff/variants/bulk', ['ids' => [$this->variant->id], 'price_usd' => 14])
            ->assertOk();

        $this->assertSame('14.00', MarketingOutbox::sole()->payload['new_price_usd']);

        // A CSV with only sku and price changes that price (a week later, so the weekly limit allows a second email).
        $this->travel(8)->days();
        $this->variant->update(['sku' => 'MEKONG-PB']);
        MarketingOutbox::query()->delete();
        $csv = "sku,price_usd\nMEKONG-PB,11.00";
        $this->app['auth']->forgetGuards();
        $token = $this->withToken($admin->createToken('t2', $admin->tokenAbilities())->plainTextToken)
            ->postJson('/api/v1/staff/books/import/preview', ['file' => UploadedFile::fake()->createWithContent('books.csv', $csv)])
            ->assertOk()->json('data.preview_token');
        $this->postJson('/api/v1/staff/books/import/confirm', ['preview_token' => $token])->assertOk();

        $this->assertSame('11.00', $this->variant->fresh()->price_usd);
        $this->assertSame(['14.00', '11.00'], [MarketingOutbox::sole()->payload['old_price_usd'], MarketingOutbox::sole()->payload['new_price_usd']]);
    }

    public function test_the_outbox_sends_the_email_and_skips_ones_that_went_stale(): void
    {
        $keeps = $this->fan(['name' => 'Keeps']);
        $turnedOff = $this->fan();
        $removed = $this->fan();
        $this->staffSetsPrice('15.00');
        $this->assertSame(3, MarketingOutbox::count());

        $turnedOff->update(['price_drop_alerts' => false]);
        Wishlist::where('customer_id', $removed->id)->delete();

        $this->assertSame(1, app(MarketingOutboxService::class)->sendPending());

        Notification::assertSentTo(new AnonymousNotifiable, PriceDropNotification::class, function (PriceDropNotification $n, array $channels, AnonymousNotifiable $to) use ($keeps) {
            $mail = $n->toMail($to);

            return $to->routes['mail'] === $keeps->email
                && $mail->subject === 'The Mekong is cheaper now'
                && str_contains($mail->introLines[0], '$20.00 → $15.00')
                && str_ends_with($mail->actionUrl, '/books/'.$this->book->id);
        });
        $this->assertSame(['sent', 'cancelled', 'cancelled'], MarketingOutbox::orderBy('id')->pluck('status')->all());
    }

    public function test_an_email_is_not_sent_when_the_price_went_back_up_before_sending(): void
    {
        $this->fan();
        $this->staffSetsPrice('15.00');
        $this->staffSetsPrice('20.00');

        $this->assertSame(0, app(MarketingOutboxService::class)->sendPending());
        $this->assertSame('The lower price is gone.', MarketingOutbox::sole()->last_error);
        Notification::assertSentTimes(PriceDropNotification::class, 0);
    }

    public function test_the_hourly_demo_reset_turns_the_emails_off(): void
    {
        $this->seed(DemoSeeder::class);
        $demo = Customer::where('email', DemoSeeder::EMAIL)->firstOrFail();
        $demo->update(['price_drop_alerts' => true]);

        $this->artisan('demo:reset')->assertSuccessful();

        $this->assertFalse($demo->fresh()->price_drop_alerts);
    }
}
