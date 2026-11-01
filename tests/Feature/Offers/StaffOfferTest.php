<?php

namespace Tests\Feature\Offers;

use App\Models\AdminAuditLog;
use App\Models\Book;
use App\Models\BookVariant;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Offer;
use App\Models\Series;
use App\Services\Offers\OfferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActsAsStaff;
use Tests\TestCase;

/** Staff offer endpoints (docs/superpowers/specs/2026-10-31-offers-design.md 4.10 and 6.7). */
class StaffOfferTest extends TestCase
{
    use ActsAsStaff, RefreshDatabase;

    private Series $holmes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->holmes = Series::factory()->create(['name' => 'Sherlock Holmes']);
    }

    private function admin(): static
    {
        return $this->asToken($this->staffToken(admin: true));
    }

    private function holmesBody(array $extra = []): array
    {
        return ['name' => 'Buy 2 Holmes', 'applies_to' => 'selected', 'min_quantity' => 2, 'percent_off' => '15',
            'targets' => [['type' => 'series', 'id' => $this->holmes->id]], ...$extra];
    }

    public function test_admin_creates_a_selected_offer_with_targets_and_it_is_audited(): void
    {
        $category = Category::factory()->create(['name' => 'Philosophy']);
        $book = Book::factory()->create(['title' => 'Meditations']);

        $response = $this->admin()->postJson('/api/v1/staff/offers', $this->holmesBody([
            'ends_at' => '2030-01-01 00:00:00', 'max_orders' => 100,
            'targets' => [
                ['type' => 'series', 'id' => $this->holmes->id],
                ['type' => 'category', 'id' => $category->id],
                ['type' => 'book', 'id' => $book->id],
                ['type' => 'book', 'id' => $book->id], // duplicate: saved once
            ],
        ]))->assertCreated()
            ->assertJsonPath('data.name', 'Buy 2 Holmes')
            ->assertJsonPath('data.percent_off', '15.00')
            ->assertJsonPath('data.used_count', 0)
            ->assertJsonPath('data.status', 'live')
            ->assertJsonPath('data.orders_count', 0)
            ->assertJsonCount(3, 'data.targets')
            ->assertJsonPath('data.targets.0', ['type' => 'series', 'id' => $this->holmes->id, 'label' => 'Sherlock Holmes', 'in_trash' => false])
            ->assertJsonPath('data.targets.1.label', 'Philosophy')
            ->assertJsonPath('data.targets.2.label', 'Meditations');

        $id = $response->json('data.id');
        $log = AdminAuditLog::where('action', 'offer.created')->sole();
        $this->assertSame($id, $log->entity_id);
        $this->assertCount(3, $log->after_data['targets']);
    }

    public function test_all_books_offer_needs_no_targets_and_refuses_them(): void
    {
        $this->admin()->postJson('/api/v1/staff/offers', ['name' => 'Buy 3', 'applies_to' => 'all', 'min_quantity' => 3, 'percent_off' => '10'])
            ->assertCreated()->assertJsonPath('data.targets', []);

        $this->admin()->postJson('/api/v1/staff/offers', ['name' => 'X', 'applies_to' => 'all', 'min_quantity' => 2, 'percent_off' => '10',
            'targets' => [['type' => 'series', 'id' => $this->holmes->id]]])
            ->assertUnprocessable()->assertJsonValidationErrors('targets');
    }

    public function test_validation_limits(): void
    {
        $this->admin()->postJson('/api/v1/staff/offers', ['name' => '', 'applies_to' => 'some', 'min_quantity' => 1, 'percent_off' => '50.01'])
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'applies_to', 'min_quantity', 'percent_off']);

        $this->admin()->postJson('/api/v1/staff/offers', $this->holmesBody(['min_quantity' => 21, 'percent_off' => '0']))
            ->assertUnprocessable()->assertJsonValidationErrors(['min_quantity', 'percent_off']);

        $this->admin()->postJson('/api/v1/staff/offers', $this->holmesBody(['starts_at' => '2030-02-01', 'ends_at' => '2030-01-01']))
            ->assertUnprocessable()->assertJsonValidationErrors('ends_at');

        $this->admin()->postJson('/api/v1/staff/offers', $this->holmesBody(['targets' => null]))
            ->assertUnprocessable()->assertJsonValidationErrors('targets');

        $trashed = Category::factory()->create();
        $trashed->delete();
        $this->admin()->postJson('/api/v1/staff/offers', $this->holmesBody(['targets' => [
            ['type' => 'category', 'id' => $trashed->id], ['type' => 'book', 'id' => 999999],
        ]]))->assertUnprocessable()->assertJsonValidationErrors('targets');
        $this->admin()->postJson('/api/v1/staff/offers', $this->holmesBody(['targets' => [['type' => 'author', 'id' => 1]]]))
            ->assertUnprocessable()->assertJsonValidationErrors('targets.0.type');

        $this->assertSame(0, Offer::count());
    }

    public function test_staff_can_view_but_not_change_customers_cannot_see(): void
    {
        $offer = Offer::factory()->create(['name' => 'Buy 2']);
        $staff = $this->staffToken();

        $this->asToken($staff)->getJson('/api/v1/staff/offers')->assertOk()->assertJsonPath('data.0.id', $offer->id);
        $this->asToken($staff)->getJson("/api/v1/staff/offers/{$offer->id}")->assertOk()->assertJsonPath('data.name', 'Buy 2');
        $this->asToken($staff)->postJson('/api/v1/staff/offers', $this->holmesBody())->assertForbidden();
        $this->asToken($staff)->patchJson("/api/v1/staff/offers/{$offer->id}", ['is_active' => false])->assertForbidden();
        $this->asToken($staff)->deleteJson("/api/v1/staff/offers/{$offer->id}")->assertForbidden();

        $customer = Customer::factory()->create();
        $this->asToken($customer->createToken('t', ['customer'])->plainTextToken)->getJson('/api/v1/staff/offers')->assertForbidden();
        $this->assertTrue($offer->fresh()->is_active);
    }

    public function test_pause_resume_and_change_targets_are_audited_and_clear_the_cache(): void
    {
        $offer = Offer::factory()->selected()->create();
        $offer->targets()->create(['series_id' => $this->holmes->id]);
        $book = Book::factory()->create(['series_id' => $this->holmes->id]);
        BookVariant::factory()->create(['book_id' => $book->id]);

        // The shop sees the live offer, then a pause hides it at once (the cache is cleared on save).
        $this->assertCount(1, app(OfferService::class)->live());
        $this->admin()->patchJson("/api/v1/staff/offers/{$offer->id}", ['is_active' => false])
            ->assertOk()->assertJsonPath('data.status', 'paused');
        $this->assertSame([], app(OfferService::class)->live());
        $this->getJson("/api/v1/books/{$book->id}")->assertJsonPath('data.offer', null);

        $this->admin()->patchJson("/api/v1/staff/offers/{$offer->id}", ['is_active' => true])->assertOk()->assertJsonPath('data.status', 'live');

        $category = Category::factory()->create();
        $this->admin()->patchJson("/api/v1/staff/offers/{$offer->id}", ['targets' => [['type' => 'category', 'id' => $category->id]]])
            ->assertOk()->assertJsonCount(1, 'data.targets')->assertJsonPath('data.targets.0.type', 'category');

        $logs = AdminAuditLog::where('action', 'offer.updated')->orderBy('id')->get();
        $this->assertSame([false, true], $logs->take(2)->map(fn ($log) => $log->after_data['is_active'])->all());
        $this->assertSame(["series:{$this->holmes->id}"], $logs[2]->before_data['targets']);
        $this->assertSame(["category:{$category->id}"], $logs[2]->after_data['targets']);

        // A PATCH that changes nothing writes no audit row.
        $this->admin()->patchJson("/api/v1/staff/offers/{$offer->id}", ['is_active' => true])->assertOk();
        $this->assertSame(3, AdminAuditLog::where('action', 'offer.updated')->count());
    }

    public function test_switching_to_all_books_drops_targets_and_dates_are_checked_against_saved_values(): void
    {
        $offer = Offer::factory()->selected()->create(['starts_at' => '2030-01-01 00:00:00']);
        $offer->targets()->create(['series_id' => $this->holmes->id]);

        $this->admin()->patchJson("/api/v1/staff/offers/{$offer->id}", ['ends_at' => '2029-12-01 00:00:00'])
            ->assertUnprocessable()->assertJsonValidationErrors('ends_at');

        $this->admin()->patchJson("/api/v1/staff/offers/{$offer->id}", ['applies_to' => 'all'])
            ->assertOk()->assertJsonPath('data.targets', [])->assertJsonPath('data.status', 'scheduled');
        $this->assertSame(0, DB::table('offer_targets')->count());

        // Back to "selected" needs targets.
        $this->admin()->patchJson("/api/v1/staff/offers/{$offer->id}", ['applies_to' => 'selected'])
            ->assertUnprocessable()->assertJsonValidationErrors('targets');
    }

    public function test_used_offer_cannot_be_deleted_unused_one_can(): void
    {
        $used = Offer::factory()->create(['used_count' => 1]);
        $unused = Offer::factory()->selected()->create();
        $unused->targets()->create(['series_id' => $this->holmes->id]);

        $this->admin()->deleteJson("/api/v1/staff/offers/{$used->id}")->assertStatus(409);
        $this->assertNotNull($used->fresh());

        $this->admin()->deleteJson("/api/v1/staff/offers/{$unused->id}")->assertNoContent();
        $this->assertNull($unused->fresh());
        $this->assertSame(0, DB::table('offer_targets')->count());
        $this->assertSame(["series:{$this->holmes->id}"], AdminAuditLog::where('action', 'offer.deleted')->sole()->before_data['targets']);
    }

    public function test_status_in_the_list_and_the_status_filter(): void
    {
        $live = Offer::factory()->create(['name' => 'live']);
        $scheduled = Offer::factory()->create(['name' => 'scheduled', 'starts_at' => now()->addDay()]);
        $paused = Offer::factory()->paused()->create(['name' => 'paused']);
        $ended = Offer::factory()->create(['name' => 'ended', 'starts_at' => now()->subDays(2), 'ends_at' => now()->subDay()]);
        $usedUp = Offer::factory()->create(['name' => 'used_up', 'max_orders' => 2, 'used_count' => 2]);
        $noBooks = Offer::factory()->selected()->create(['name' => 'no_books']);
        $trashed = Series::factory()->create();
        $noBooks->targets()->create(['series_id' => $trashed->id]);
        $trashed->delete(); // its only target is in the trash

        $all = $this->admin()->getJson('/api/v1/staff/offers?per_page=50')->assertOk()->json('data');
        $statuses = collect($all)->mapWithKeys(fn ($row) => [$row['name'] => $row['status']])->all();
        foreach (Offer::STATUSES as $status) {
            $this->assertSame($status, $statuses[$status]); // each offer is named after the status it must show
        }
        $this->assertTrue(collect($all)->firstWhere('name', 'no_books')['targets'][0]['in_trash']);

        foreach (['live' => $live, 'scheduled' => $scheduled, 'paused' => $paused, 'ended' => $ended, 'used_up' => $usedUp, 'no_books' => $noBooks] as $status => $offer) {
            $this->admin()->getJson("/api/v1/staff/offers?status={$status}")->assertOk()
                ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $offer->id);
        }

        $this->admin()->getJson('/api/v1/staff/offers?q=PAUS')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $paused->id);
        $this->admin()->getJson('/api/v1/staff/offers?status=bogus')->assertUnprocessable();
    }
}
