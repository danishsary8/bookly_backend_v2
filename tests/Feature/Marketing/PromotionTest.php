<?php

namespace Tests\Feature\Marketing;

use App\Models\AdminAuditLog;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Promotion;
use App\Services\Offers\OfferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsStaff;
use Tests\TestCase;

/** Promotion pop-ups (owner 2026-11-12): the public "current" promotion, its counters, and the staff pages. */
class PromotionTest extends TestCase
{
    use ActsAsStaff, RefreshDatabase;

    public function test_current_is_the_newest_live_promotion_with_its_live_offer(): void
    {
        $this->getJson('/api/v1/promotions/current')->assertOk()->assertJsonPath('data', null);

        $offer = Offer::factory()->create(['name' => 'Buy 2', 'min_quantity' => 2, 'percent_off' => '15.00', 'free_delivery' => true]);
        app(OfferService::class)->forget();
        Promotion::factory()->create(['title_en' => 'Hidden', 'is_active' => false]);
        Promotion::factory()->create(['title_en' => 'Ended', 'ends_at' => now()->subDay()]);
        Promotion::factory()->create(['title_en' => 'Later', 'starts_at' => now()->addDay()]);
        Promotion::factory()->create(['title_en' => 'Older', 'starts_at' => now()->subDays(5)]);
        $live = Promotion::factory()->create(['title_en' => 'Big sale', 'title_km' => 'បញ្ចុះតម្លៃ', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(3), 'offer_id' => $offer->id, 'cta_url' => '/books?sort=newest']);

        $this->getJson('/api/v1/promotions/current')->assertOk()
            ->assertJsonPath('data.id', $live->id)
            ->assertJsonPath('data.title_km', 'បញ្ចុះតម្លៃ')
            ->assertJsonPath('data.cta_url', '/books?sort=newest')
            ->assertJsonPath('data.offer.percent_off', '15.00')
            ->assertJsonPath('data.offer.free_delivery', true);

        // A paused offer is not promised any more; the promotion itself still shows.
        $offer->update(['is_active' => false]);
        app(OfferService::class)->forget();
        $this->getJson('/api/v1/promotions/current')->assertOk()->assertJsonPath('data.id', $live->id)->assertJsonPath('data.offer', null);
    }

    public function test_views_and_clicks_are_counted_without_a_login(): void
    {
        $promotion = Promotion::factory()->create();

        $this->postJson("/api/v1/promotions/{$promotion->id}/view")->assertNoContent();
        $this->postJson("/api/v1/promotions/{$promotion->id}/view")->assertNoContent();
        $this->postJson("/api/v1/promotions/{$promotion->id}/click")->assertNoContent();
        $this->postJson('/api/v1/promotions/999999/view')->assertNotFound();

        $this->assertSame([2, 1], [$promotion->fresh()->views, $promotion->fresh()->clicks]);
    }

    public function test_staff_create_show_hide_and_delete_and_see_results(): void
    {
        $staff = $this->staffToken();
        $offer = Offer::factory()->create();

        $id = $this->asToken($staff)->postJson('/api/v1/staff/promotions', [
            'title_en' => 'Full set week', 'body_en' => 'Save 20% on full sets.', 'cta_url' => '/series', 'offer_id' => $offer->id,
            'starts_at' => now()->subDay()->toIso8601String(), 'ends_at' => now()->addDays(6)->toIso8601String(),
        ])->assertCreated()->assertJsonPath('data.status', 'hidden')->assertJsonPath('data.offer_name', $offer->name)->json('data.id');

        // Shown, then counted: one order with the offer inside the dates, one before them, one cancelled.
        $this->asToken($staff)->patchJson("/api/v1/staff/promotions/{$id}", ['is_active' => true])->assertOk()->assertJsonPath('data.status', 'live');
        Order::factory()->create(['offer_id' => $offer->id, 'total_amount' => '24.00', 'placed_at' => now()]);
        Order::factory()->create(['offer_id' => $offer->id, 'total_amount' => '50.00', 'placed_at' => now()->subDays(3)]);
        Order::factory()->create(['offer_id' => $offer->id, 'total_amount' => '10.00', 'placed_at' => now(), 'status' => 'cancelled']);
        $this->postJson("/api/v1/promotions/{$id}/view")->assertNoContent();

        $this->asToken($staff)->getJson('/api/v1/staff/promotions')->assertOk()
            ->assertJsonPath('data.0.views', 1)
            ->assertJsonPath('data.0.orders_count', 1)
            ->assertJsonPath('data.0.revenue_usd', '24.00');

        $this->assertSame(['promotion.created', 'promotion.updated'], AdminAuditLog::where('entity_type', 'promotion')->orderBy('id')->pluck('action')->all());

        $this->asToken($staff)->deleteJson("/api/v1/staff/promotions/{$id}")->assertNoContent();
        $this->assertSame(0, Promotion::count());
    }

    public function test_validation_and_who_may_use_the_staff_routes(): void
    {
        $staff = $this->staffToken();
        $body = ['title_en' => 'Sale'];

        $this->asToken($staff)->postJson('/api/v1/staff/promotions', [...$body, 'cta_url' => 'https://elsewhere.example/'])->assertJsonValidationErrors('cta_url');
        $this->asToken($staff)->postJson('/api/v1/staff/promotions', [...$body, 'cta_url' => '//elsewhere.example'])->assertJsonValidationErrors('cta_url');
        $this->asToken($staff)->postJson('/api/v1/staff/promotions', [...$body, 'image_url' => 'http://plain.example/a.jpg'])->assertJsonValidationErrors('image_url');
        $this->asToken($staff)->postJson('/api/v1/staff/promotions', [...$body, 'offer_id' => 999])->assertJsonValidationErrors('offer_id');
        $this->asToken($staff)->postJson('/api/v1/staff/promotions', [...$body, 'starts_at' => '2030-01-02', 'ends_at' => '2030-01-01'])->assertJsonValidationErrors('ends_at');

        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->getJson('/api/v1/staff/promotions')->assertUnauthorized();
    }
}
