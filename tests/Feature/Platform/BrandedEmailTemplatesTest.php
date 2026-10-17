<?php

namespace Tests\Feature\Platform;

use App\Enums\BookFormat;
use App\Enums\OrderStatus;
use App\Enums\VerificationPurpose;
use App\Models\Book;
use App\Models\BookVariant;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Notifications\OrderPlacedNotification;
use App\Notifications\OrderStatusChangedNotification;
use App\Notifications\OtpCodeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandedEmailTemplatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_placed_email_renders_with_bookly_branding_and_action_button(): void
    {
        $customer = Customer::factory()->create(['name' => 'Sok Dara', 'email' => 'dara@example.com']);
        $book = Book::factory()->create(['title' => 'The Great Gatsby']);
        $variant = BookVariant::factory()->for($book)->create(['format' => BookFormat::Hardcover]);

        $order = Order::factory()->for($customer)->create([
            'order_number' => 'ORD-20261017-HTML',
            'status' => OrderStatus::Pending,
            'subtotal' => 25.00,
            'shipping_fee' => 1.50,
            'total_amount' => 26.50,
            'shipping_city' => 'Phnom Penh',
            'shipping_country' => 'Cambodia',
        ]);

        OrderItem::factory()->for($order)->create([
            'book_variant_id' => $variant->id,
            'quantity' => 1,
            'subtotal' => 25.00,
        ]);

        config(['shop.url' => 'https://bookly.test']);

        $notification = new OrderPlacedNotification($order);
        $rendered = (string) $notification->toMail($customer)->render();

        $this->assertStringContainsString('Bookly', $rendered);
        $this->assertStringContainsString('Phnom Penh, Cambodia', $rendered);
        $this->assertStringContainsString('ORD-20261017-HTML', $rendered);
        $this->assertStringContainsString('The Great Gatsby', $rendered);
        $this->assertStringContainsString('https://bookly.test/account/orders/'.$order->id, $rendered);
        $this->assertStringContainsString('View your order', $rendered);
    }

    public function test_order_status_changed_email_includes_action_button_and_tracking_note(): void
    {
        $customer = Customer::factory()->create(['name' => 'Bopha', 'email' => 'bopha@example.com']);
        $order = Order::factory()->for($customer)->create([
            'order_number' => 'ORD-20261017-SHIP',
            'status' => OrderStatus::Shipped,
        ]);

        config(['shop.url' => 'https://bookly.test']);

        $notification = new OrderStatusChangedNotification($order, OrderStatus::Shipped, 'Tracking: J&T 882910');
        $rendered = (string) $notification->toMail($customer)->render();

        $this->assertStringContainsString('Bookly', $rendered);
        $this->assertStringContainsString('Your order has been shipped.', $rendered);
        $this->assertStringContainsString('ORD-20261017-SHIP', $rendered);
        $this->assertStringContainsString('Tracking: J&amp;T 882910', $rendered);
        $this->assertStringContainsString('View your order', $rendered);
        $this->assertStringContainsString('https://bookly.test/account/orders/'.$order->id, $rendered);
    }

    public function test_otp_email_renders_with_branded_header_and_code(): void
    {
        $customer = Customer::factory()->create(['name' => 'Srey Leap']);
        $notification = new OtpCodeNotification('789123', VerificationPurpose::EmailVerify, 15);

        $rendered = (string) $notification->toMail($customer)->render();

        $this->assertStringContainsString('Bookly', $rendered);
        $this->assertStringContainsString('789123', $rendered);
        $this->assertStringContainsString('This code expires in 15 minutes.', $rendered);
        $this->assertStringContainsString('Phnom Penh, Cambodia', $rendered);
    }

    public function test_khmer_locale_renders_khmer_action_button(): void
    {
        $customer = Customer::factory()->create(['name' => 'Sokha', 'locale' => 'km']);
        $order = Order::factory()->for($customer)->create(['order_number' => 'ORD-KM-99']);

        config(['shop.url' => 'https://bookly.test']);
        app()->setLocale('km');

        $notification = new OrderPlacedNotification($order);
        $rendered = (string) $notification->toMail($customer)->render();

        $this->assertStringContainsString('Bookly', $rendered);
        $this->assertStringContainsString('មើលការបញ្ជាទិញរបស់អ្នក', $rendered);
        $this->assertStringContainsString('https://bookly.test/account/orders/'.$order->id, $rendered);
    }
}
