<?php

namespace Tests\Feature\Reviews;

use App\Models\AdminAuditLog;
use App\Models\Customer;
use App\Models\Review;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsStaff;
use Tests\Concerns\BuildsOrders;
use Tests\TestCase;

class StaffReviewModerationTest extends TestCase
{
    use ActsAsStaff, BuildsOrders, RefreshDatabase;

    private function makeReview(int $rating, string $comment): Review
    {
        $variant = $this->physical();
        $customer = Customer::factory()->create();
        $order = $this->deliveredOrder($customer, [[$variant, 1]]);

        return Review::create(['book_id' => $variant->book_id, 'customer_id' => $customer->id,
            'order_item_id' => $order->items[0]->id, 'rating' => $rating, 'comment' => $comment]);
    }

    public function test_staff_hide_and_show_reviews_with_audit_log(): void
    {
        $review = $this->makeReview(1, 'Spam link here');
        $staff = $this->staffToken();

        $this->asToken($staff)->postJson("/api/v1/staff/reviews/{$review->id}/hide", ['note' => 'Contains a link'])
            ->assertOk()->assertJsonPath('data.is_visible', false);
        $this->getJson("/api/v1/books/{$review->book_id}/reviews")->assertJsonCount(0, 'data');

        $this->asToken($staff)->postJson("/api/v1/staff/reviews/{$review->id}/hide")->assertOk(); // no change, no extra log
        $this->asToken($staff)->postJson("/api/v1/staff/reviews/{$review->id}/show")->assertOk()->assertJsonPath('data.is_visible', true);

        $this->assertSame(['review.hidden', 'review.shown'], AdminAuditLog::orderBy('id')->pluck('action')->all());
        $this->assertSame('Contains a link', AdminAuditLog::orderBy('id')->first()->after_data['note']);
    }

    public function test_hidden_review_stays_hidden_when_the_customer_edits_it(): void
    {
        $review = $this->makeReview(1, 'Bad words');
        $review->update(['is_approved' => false]);
        $token = $review->customer->createToken('t', ['customer'])->plainTextToken;

        $this->asToken($token)->patchJson("/api/v1/reviews/{$review->id}", ['comment' => 'Nicer words'])
            ->assertOk()->assertJsonPath('data.is_visible', false);
    }

    public function test_staff_list_filters(): void
    {
        $low = $this->makeReview(1, 'Terrible binding');
        $this->makeReview(5, 'Wonderful');
        $low->update(['is_approved' => false]);
        $staff = $this->staffToken();

        $this->asToken($staff)->getJson('/api/v1/staff/reviews')->assertOk()->assertJsonCount(2, 'data');
        $this->asToken($staff)->getJson('/api/v1/staff/reviews?visible=0')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $low->id);
        $this->asToken($staff)->getJson('/api/v1/staff/reviews?max_rating=2')->assertJsonCount(1, 'data');
        $this->asToken($staff)->getJson('/api/v1/staff/reviews?q=binding')->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.customer.email', $low->customer->email);
    }

    public function test_customers_cannot_moderate(): void
    {
        $review = $this->makeReview(1, 'x');
        $token = Customer::factory()->create()->createToken('t', ['customer'])->plainTextToken;

        $this->asToken($token)->postJson("/api/v1/staff/reviews/{$review->id}/hide")->assertForbidden();
    }
}
