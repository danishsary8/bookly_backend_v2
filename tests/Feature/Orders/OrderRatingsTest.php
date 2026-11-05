<?php

namespace Tests\Feature\Orders;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderRating;
use App\Models\StaffUser;
use App\Notifications\LowOrderRatingNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\ActsAsStaff;
use Tests\TestCase;

class OrderRatingsTest extends TestCase
{
    use ActsAsStaff, RefreshDatabase;

    private function shopper(): Customer
    {
        $customer = Customer::factory()->create();
        $this->asToken($customer->createToken('test', ['customer'])->plainTextToken);

        return $customer;
    }

    public function test_only_owner_and_delivered_once_with_seven_day_edit_window(): void
    {
        $owner = $this->shopper();
        $order = Order::factory()->create(['customer_id' => $owner->id]);
        $url = "/api/v1/orders/{$order->id}/rating";
        $this->postJson($url, ['stars' => 5])->assertUnprocessable();
        $order->update(['status' => 'delivered']);
        $this->postJson($url, ['stars' => 5, 'comment' => 'Thanks'])->assertCreated();
        $this->postJson($url, ['stars' => 4])->assertUnprocessable();
        $this->patchJson($url, ['stars' => 4])->assertOk();
        $this->travel(7)->days();
        $this->patchJson($url, ['stars' => 3])->assertUnprocessable();
        $this->shopper();
        $this->getJson($url)->assertNotFound();
        $this->patchJson($url, ['stars' => 4])->assertNotFound();
    }

    public function test_hidden_comments_do_not_return_to_owner_and_input_is_plain_text(): void
    {
        $owner = $this->shopper();
        $token = $owner->createToken('owner', ['customer'])->plainTextToken;
        $order = Order::factory()->create(['customer_id' => $owner->id, 'status' => 'delivered']);
        $url = "/api/v1/orders/{$order->id}/rating";
        $this->postJson($url, ['stars' => 6])->assertUnprocessable();
        $this->postJson($url, ['stars' => 4, 'comment' => '<script>x</script>'])->assertUnprocessable();
        $id = $this->postJson($url, ['stars' => 4, 'comment' => 'Private complaint'])->assertCreated()->json('data.id');
        $this->asToken($this->staffToken())->postJson("/api/v1/staff/ratings/{$id}/hide")->assertOk()->assertJsonPath('data.comment', null);
        $this->asToken($token)->patchJson($url, ['stars' => 5, 'comment' => 'Edited'])->assertOk()->assertJsonPath('data.comment', null);
    }

    public function test_threshold_and_hourly_cache_do_not_invent_numbers(): void
    {
        $owner = $this->shopper();
        for ($i = 0; $i < 9; $i++) {
            $order = Order::factory()->create(['customer_id' => $owner->id, 'status' => 'delivered']);
            OrderRating::forceCreate(['customer_id' => $owner->id, 'order_id' => $order->id, 'stars' => 4]);
        }
        $this->getJson('/api/v1/store-rating')->assertOk()->assertJsonPath('data', null);
        $order = Order::factory()->create(['customer_id' => $owner->id, 'status' => 'delivered']);
        $this->postJson("/api/v1/orders/{$order->id}/rating", ['stars' => 5])->assertCreated();
        $this->getJson('/api/v1/store-rating')->assertJsonPath('data', null);
        $this->travel(61)->minutes();
        $this->getJson('/api/v1/store-rating')->assertOk()->assertJsonPath('data.count', 10)->assertJsonPath('data.average', 4.1);
    }

    public function test_low_rating_alert_is_sent_once_on_entering_low_range(): void
    {
        Notification::fake();
        Http::fake();
        config(['services.ops_alerts.token' => 'test-only', 'services.ops_alerts.staff_chat_id' => 'test-group']);
        $staff = StaffUser::factory()->create();
        $owner = $this->shopper();
        $order = Order::factory()->create(['customer_id' => $owner->id, 'status' => 'delivered']);
        $url = "/api/v1/orders/{$order->id}/rating";
        $this->postJson($url, ['stars' => 1])->assertCreated();
        $this->patchJson($url, ['stars' => 2])->assertOk();
        Notification::assertSentToTimes($staff, LowOrderRatingNotification::class, 1);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request['text'], $order->order_number));
    }
}
