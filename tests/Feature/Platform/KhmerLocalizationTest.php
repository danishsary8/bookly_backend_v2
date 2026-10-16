<?php

namespace Tests\Feature\Platform;

use App\Enums\OrderStatus;
use App\Enums\ReturnStatus;
use App\Enums\VerificationPurpose;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Notifications\AccountReopenedNotification;
use App\Notifications\AccountSecurityNotice;
use App\Notifications\OrderPlacedNotification;
use App\Notifications\OrderStatusChangedNotification;
use App\Notifications\OtpCodeNotification;
use App\Notifications\ReturnUpdatedNotification;
use App\Notifications\WelcomeNotification;
use App\Services\Returns\ReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsStaff;
use Tests\TestCase;

class KhmerLocalizationTest extends TestCase
{
    use ActsAsStaff, RefreshDatabase;

    private function withLocale(string $locale, callable $callback): mixed
    {
        $previous = app()->getLocale();
        app()->setLocale($locale);

        try {
            return $callback();
        } finally {
            app()->setLocale($previous);
        }
    }

    public function test_khmer_customer_receives_khmer_email_notifications(): void
    {
        $customer = Customer::factory()->create([
            'name' => 'Sokha',
            'email' => 'sokha@example.com',
            'locale' => 'km',
        ]);

        $this->assertSame('km', $customer->preferredLocale());

        $this->withLocale($customer->preferredLocale(), function () use ($customer) {
            // 1. OTP Notification
            $otp = new OtpCodeNotification('654321', VerificationPurpose::EmailVerify, 10);
            $otpMail = $otp->toMail($customer);
            $this->assertSame('ផ្ទៀងផ្ទាត់អាសយដ្ឋានអ៊ីមែលរបស់អ្នក', $otpMail->subject);
            $this->assertStringContainsString('654321', implode(' ', $otpMail->introLines));

            // 2. Welcome Notification
            $welcome = new WelcomeNotification;
            $welcomeMail = $welcome->toMail($customer);
            $this->assertSame('សូមស្វាគមន៍មកកាន់ Bookly', $welcomeMail->subject);
            $this->assertSame('សូមស្វាគមន៍ Sokha!', $welcomeMail->greeting);
            $this->assertStringContainsString('គណនី Bookly របស់អ្នកបានរួចរាល់ហើយ', implode(' ', $welcomeMail->introLines));

            // 3. Order Placed Notification
            $order = Order::factory()->for($customer)->create([
                'order_number' => 'ORD-KM-101',
                'subtotal' => 25.00,
                'total_amount' => 27.00,
                'shipping_fee' => 2.00,
                'discount_amount' => 0.00,
                'shipping_recipient_name' => 'Sokha',
                'shipping_address_line1' => 'Street 271',
                'shipping_city' => 'Phnom Penh',
                'shipping_country' => 'Cambodia',
            ]);
            $orderPlaced = new OrderPlacedNotification($order);
            $orderPlacedMail = $orderPlaced->toMail($customer);
            $this->assertSame('ការបញ្ជាទិញត្រូវបានបញ្ជាក់៖ ORD-KM-101', $orderPlacedMail->subject);
            $this->assertSame('សូមអរគុណសម្រាប់ការបញ្ជាទិញរបស់អ្នក Sokha!', $orderPlacedMail->greeting);

            // 4. Order Status Changed Notification (Processing & Delivered)
            $statusNotif = new OrderStatusChangedNotification($order, OrderStatus::Processing);
            $statusMail = $statusNotif->toMail($customer);
            $this->assertSame('យើងកំពុងរៀបចំការបញ្ជាទិញរបស់អ្នក ORD-KM-101', $statusMail->subject);

            // 5. Return Updated Notification (bank transfer with a reference)
            $return = OrderReturn::create([
                'order_id' => $order->id,
                'customer_id' => $customer->id,
                'reason' => 'Damaged pages',
                'status' => ReturnStatus::Refunded,
                'requested_at' => now(),
                'refund_amount' => 15.00,
                'refund_method' => 'bank_transfer',
                'refund_reference' => 'SC-TEST-999',
            ]);
            $return->setRelation('order', $order);
            $returnNotif = new ReturnUpdatedNotification($return);
            $returnMail = $returnNotif->toMail($customer);
            $this->assertSame('ការសងប្រាក់សម្រាប់ការបញ្ជាទិញ ORD-KM-101 ត្រូវបានបញ្ចប់', $returnMail->subject);
            $this->assertStringContainsString('SC-TEST-999', implode(' ', $returnMail->introLines));

            // 6. Security & Reopen Notifications
            $security = new AccountSecurityNotice('Your Bookly password was reset', 'The password of your Bookly account was just reset with a code sent to this email, and every device was signed out.');
            $secMail = $security->toMail($customer);
            $this->assertSame('ពាក្យសម្ងាត់ Bookly របស់អ្នកត្រូវបានកំណត់ឡើងវិញ', $secMail->subject);

            $reopen = new AccountReopenedNotification;
            $reopenMail = $reopen->toMail($customer);
            $this->assertSame('គណនី Bookly របស់អ្នកត្រូវបានបើកឡើងវិញហើយ', $reopenMail->subject);
        });
    }

    public function test_english_customer_receives_english_email_notifications(): void
    {
        $customer = Customer::factory()->create([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'locale' => 'en',
        ]);

        $this->assertSame('en', $customer->preferredLocale());

        $this->withLocale($customer->preferredLocale(), function () use ($customer) {
            $otp = new OtpCodeNotification('112233', VerificationPurpose::EmailVerify, 10);
            $otpMail = $otp->toMail($customer);
            $this->assertSame('Verify your email address', $otpMail->subject);

            $welcome = new WelcomeNotification;
            $welcomeMail = $welcome->toMail($customer);
            $this->assertSame('Welcome to Bookly', $welcomeMail->subject);
        });
    }

    public function test_api_localized_messages_and_validation_with_accept_language_header(): void
    {
        // 1. Validation in Khmer with Accept-Language: km
        $resKm = $this->withHeaders(['Accept-Language' => 'km'])
            ->postJson('/api/v1/auth/register', [
                'name' => '',
                'email' => 'not-an-email',
            ]);

        $resKm->assertStatus(422);
        $resKm->assertJsonValidationErrors(['name', 'email']);
        $this->assertSame('សូមបញ្ចូល ឈ្មោះ។', $resKm->json('errors.name.0'));
        $this->assertSame('អ៊ីមែល ត្រូវតែជាអាសយដ្ឋានអ៊ីមែលត្រឹមត្រូវ។', $resKm->json('errors.email.0'));

        $this->flushHeaders();

        // 2. Validation in English without Accept-Language or with Accept-Language: en
        $resEn = $this->postJson('/api/v1/auth/register', [
            'name' => '',
            'email' => 'not-an-email',
        ]);

        $resEn->assertStatus(422);
        $resEn->assertJsonValidationErrors(['name', 'email']);
        $this->assertSame('The name field is required.', $resEn->json('errors.name.0'));
        $this->assertSame('The email field must be a valid email address.', $resEn->json('errors.email.0'));

        // 3. ReturnService blockReason localization
        $customer = Customer::factory()->create();
        $order = Order::factory()->for($customer)->create(['status' => OrderStatus::Pending]);

        $this->withLocale('km', function () use ($order) {
            $reason = app(ReturnService::class)->blockReason($order);
            $this->assertSame('មានតែការបញ្ជាទិញដែលបានដឹកជញ្ជូនរួចប៉ុណ្ណោះដែលអាចប្តូរទំនិញបាន។', $reason);
        });

        $this->withLocale('en', function () use ($order) {
            $reason = app(ReturnService::class)->blockReason($order);
            $this->assertSame('Only delivered orders can be returned.', $reason);
        });
    }

    public function test_staff_routes_ignore_accept_language_header(): void
    {
        $adminToken = $this->staffToken(admin: true);

        // Staff member creation with missing fields and Accept-Language: km
        $response = $this->asToken($adminToken)
            ->withHeaders(['Accept-Language' => 'km'])
            ->postJson('/api/v1/staff/members', [
                'name' => '',
                'email' => 'not-an-email',
            ]);

        $response->assertStatus(422);
        // Must stay in English!
        $this->assertSame('The name field is required.', $response->json('errors.name.0'));
    }

    public function test_customer_profile_update_and_registration_locale(): void
    {
        // 1. Register with locale 'km'
        $reg = $this->postJson('/api/v1/auth/register', [
            'name' => 'Bopha',
            'email' => 'bopha@example.com',
            'password' => 'Secret123!',
            'password_confirmation' => 'Secret123!',
            'locale' => 'km',
        ])->assertStatus(201);

        $reg->assertJsonPath('customer.locale', 'km');
        $this->assertDatabaseHas('customers', [
            'email' => 'bopha@example.com',
            'locale' => 'km',
        ]);

        $customer = Customer::where('email', 'bopha@example.com')->first();
        $token = $customer->createToken('t', ['customer'])->plainTextToken;

        // 2. Update locale to 'en' via PATCH /api/v1/me
        $this->asToken($token)
            ->patchJson('/api/v1/me', ['locale' => 'en'])
            ->assertOk()
            ->assertJsonPath('data.locale', 'en');

        $this->assertSame('en', $customer->fresh()->locale);

        // 3. Update locale to 'km' via PATCH /api/v1/me
        $this->asToken($token)
            ->patchJson('/api/v1/me', ['locale' => 'km'])
            ->assertOk()
            ->assertJsonPath('data.locale', 'km');

        $this->assertSame('km', $customer->fresh()->locale);

        // 4. Reject invalid locale like 'fr'
        $this->asToken($token)
            ->patchJson('/api/v1/me', ['locale' => 'fr'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['locale']);
    }
}
