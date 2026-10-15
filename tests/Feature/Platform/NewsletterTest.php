<?php

namespace Tests\Feature\Platform;

use App\Enums\StaffRole;
use App\Models\Book;
use App\Models\Customer;
use App\Models\MarketingOutbox;
use App\Models\NewsletterCampaign;
use App\Models\NewsletterSubscriber;
use App\Models\StaffUser;
use App\Notifications\NewsletterCampaignNotification;
use App\Notifications\NewsletterConfirmNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NewsletterTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_subscribe_no_enumeration_and_sends_confirm_email(): void
    {
        Notification::fake();

        // 1. Fresh subscription -> returns 202
        $res = $this->postJson('/api/v1/newsletter/subscribe', [
            'email' => 'Reader@Example.COM',
            'locale' => 'km',
        ]);

        $res->assertStatus(202)
            ->assertJson(['message' => 'Check your inbox to confirm.']);

        $subscriber = NewsletterSubscriber::where('email', 'reader@example.com')->first();
        $this->assertNotNull($subscriber);
        $this->assertSame('pending', $subscriber->status);
        $this->assertSame('km', $subscriber->locale);
        $this->assertNotNull($subscriber->confirm_token_hash);
        $this->assertNotNull($subscriber->unsubscribe_token_hash);

        Notification::assertSentTo(
            new AnonymousNotifiable,
            NewsletterConfirmNotification::class,
            function ($notification, $channels, $notifiable) {
                return $notifiable->routes['mail'] === 'reader@example.com';
            }
        );

        // 2. Already confirmed subscriber -> still returns identical 202 message (no enumeration)
        $subscriber->update(['status' => 'confirmed', 'confirmed_at' => now()]);
        Notification::fake();

        $res2 = $this->postJson('/api/v1/newsletter/subscribe', [
            'email' => 'reader@example.com',
        ]);

        $res2->assertStatus(202)
            ->assertJson(['message' => 'Check your inbox to confirm.']);

        // Should not have sent confirmation email again
        Notification::assertNothingSent();
    }

    public function test_subscribe_throttles_confirm_email_once_per_10_minutes(): void
    {
        Notification::fake();

        $email = 'throttle_test@example.com';

        // First attempt sends email
        $this->postJson('/api/v1/newsletter/subscribe', ['email' => $email])->assertStatus(202);
        Notification::assertSentTimes(NewsletterConfirmNotification::class, 1);

        // Immediate second attempt returns 202 but throttles email send
        Notification::fake();
        $this->postJson('/api/v1/newsletter/subscribe', ['email' => $email])->assertStatus(202);
        Notification::assertNothingSent();
    }

    public function test_confirm_subscription_with_valid_and_invalid_tokens(): void
    {
        $email = 'optin@example.com';
        $rawToken = NewsletterSubscriber::generateRawToken();

        $subscriber = NewsletterSubscriber::create([
            'email' => $email,
            'locale' => 'en',
            'status' => 'pending',
            'confirm_token_hash' => NewsletterSubscriber::hashToken($rawToken),
            'unsubscribe_token_hash' => NewsletterSubscriber::hashToken('dummy-unsub'),
        ]);

        // Invalid token
        $this->postJson('/api/v1/newsletter/confirm', ['token' => 'wrong-token'])
            ->assertStatus(422)
            ->assertJson(['message' => 'Invalid or expired confirmation link.']);

        $this->assertSame('pending', $subscriber->fresh()->status);

        // Valid token
        $this->postJson('/api/v1/newsletter/confirm', ['token' => $rawToken])
            ->assertStatus(200)
            ->assertJson(['message' => 'Your subscription has been confirmed. Thank you!']);

        $fresh = $subscriber->fresh();
        $this->assertSame('confirmed', $fresh->status);
        $this->assertNotNull($fresh->confirmed_at);
        $this->assertNull($fresh->confirm_token_hash);
    }

    public function test_unsubscribe_with_valid_token_and_one_click_rfc8058(): void
    {
        $email = 'unsub_test@example.com';
        $rawUnsubToken = hash_hmac('sha256', 'newsletter:unsub:'.$email, config('app.key'));

        $subscriber = NewsletterSubscriber::create([
            'email' => $email,
            'locale' => 'en',
            'status' => 'confirmed',
            'confirmed_at' => now(),
            'unsubscribe_token_hash' => NewsletterSubscriber::hashToken($rawUnsubToken),
        ]);

        // RFC 8058 one-click unsubscribe
        $this->postJson('/api/v1/newsletter/unsubscribe/one-click?token='.$rawUnsubToken, [
            'List-Unsubscribe' => 'One-Click',
        ])
            ->assertStatus(200)
            ->assertJson(['message' => 'Unsubscribed successfully.']);

        $this->assertSame('unsubscribed', $subscriber->fresh()->status);
        $this->assertNotNull($subscriber->fresh()->unsubscribed_at);

        // Standard web form unsubscribe
        $rawUnsubToken2 = hash_hmac('sha256', 'newsletter:unsub:user2@example.com', config('app.key'));
        $subscriber2 = NewsletterSubscriber::create([
            'email' => 'user2@example.com',
            'locale' => 'en',
            'status' => 'confirmed',
            'confirmed_at' => now(),
            'unsubscribe_token_hash' => NewsletterSubscriber::hashToken($rawUnsubToken2),
        ]);

        $this->postJson('/api/v1/newsletter/unsubscribe', ['token' => $rawUnsubToken2])
            ->assertStatus(200)
            ->assertJson(['message' => 'You have been unsubscribed from the newsletter.']);

        $this->assertSame('unsubscribed', $subscriber2->fresh()->status);
    }

    private function actingAsAdmin(StaffUser $user): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken(
            $user->createToken('staff-test', $user->tokenAbilities())->plainTextToken
        );
    }

    private function actingAsStaffUser(StaffUser $user): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken(
            $user->createToken('staff-test', $user->tokenAbilities())->plainTextToken
        );
    }

    public function test_newsletter_notification_has_rfc8058_headers_and_escapes_html(): void
    {
        $book = Book::factory()->create([
            'title' => 'Safe Book Title',
        ]);

        $payload = [
            'subject' => 'Weekly Reads',
            'body' => "Line 1 paragraph.\n\nLine 2 with <script>alert('xss')</script> tag.",
            'books' => [
                [
                    'id' => $book->id,
                    'title' => $book->title,
                    'price' => '$19.99',
                    'url' => 'https://example.com/books/'.$book->slug,
                ],
            ],
            'unsubscribe_token' => 'sample-raw-token',
        ];

        $notification = new NewsletterCampaignNotification($payload);
        $notifiable = new AnonymousNotifiable;
        $mail = $notification->toMail($notifiable);

        $rendered = $mail->render();

        // Check HTML escaping
        $this->assertStringNotContainsString("<script>alert('xss')</script>", $rendered);
        $this->assertStringContainsString('&lt;script&gt;', $rendered);

        // Check book title & link
        $this->assertStringContainsString('Safe Book Title', $rendered);
        $this->assertStringContainsString('https://example.com/books/'.$book->slug, $rendered);

        // Check unsubscribe link in footer
        $this->assertStringContainsString('sample-raw-token', $rendered);
    }

    public function test_verified_customer_can_toggle_newsletter_without_confirm_email(): void
    {
        $customer = Customer::factory()->create([
            'email' => 'customer_vip@example.com',
            'email_verified_at' => now(),
            'locale' => 'km',
        ]);

        $token = $customer->createToken('test', ['customer'])->plainTextToken;

        // Get status when none
        $res = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/me/newsletter');
        $res->assertStatus(200)
            ->assertJson([
                'subscribed' => false,
                'status' => 'none',
            ]);

        // Subscribe directly
        $resSub = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/me/newsletter', ['subscribed' => true]);
        $resSub->assertStatus(200)
            ->assertJson([
                'subscribed' => true,
                'status' => 'confirmed',
                'locale' => 'km',
            ]);

        $this->assertDatabaseHas('newsletter_subscribers', [
            'email' => 'customer_vip@example.com',
            'status' => 'confirmed',
            'customer_id' => $customer->id,
        ]);

        // Unsubscribe directly
        $resUnsub = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/me/newsletter', ['subscribed' => false]);
        $resUnsub->assertStatus(200)
            ->assertJson([
                'subscribed' => false,
                'status' => 'unsubscribed',
            ]);

        $this->assertDatabaseHas('newsletter_subscribers', [
            'email' => 'customer_vip@example.com',
            'status' => 'unsubscribed',
        ]);
    }

    public function test_unverified_customer_cannot_toggle_newsletter(): void
    {
        $customer = Customer::factory()->create([
            'email' => 'unverified@example.com',
            'email_verified_at' => null,
        ]);

        $token = $customer->createToken('test', ['customer'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/me/newsletter', ['subscribed' => true])
            ->assertStatus(403);
    }

    public function test_staff_and_admin_permissions_on_newsletter_endpoints(): void
    {
        // 1. Guest -> 401
        $this->getJson('/api/v1/staff/newsletter/summary')->assertStatus(401);

        // 2. Regular staff (non-admin) -> 403
        $staff = StaffUser::factory()->withTwoFactor()->create(['role' => StaffRole::Staff]);
        $this->actingAsStaffUser($staff)
            ->getJson('/api/v1/staff/newsletter/summary')
            ->assertStatus(403);

        // 3. Admin -> 200
        $admin = StaffUser::factory()->admin()->withTwoFactor()->create();
        $this->actingAsAdmin($admin)
            ->getJson('/api/v1/staff/newsletter/summary')
            ->assertStatus(200)
            ->assertJsonStructure(['confirmed', 'pending', 'unsubscribed', 'total']);
    }

    public function test_admin_campaigns_crud_and_status_constraints(): void
    {
        $admin = StaffUser::factory()->admin()->withTwoFactor()->create(['email' => 'admin@bookly.test']);

        $book = Book::factory()->create();

        // 1. Create campaign
        $createRes = $this->actingAsAdmin($admin)
            ->postJson('/api/v1/staff/newsletter/campaigns', [
                'subject_en' => 'Monthly Highlights',
                'subject_km' => 'ចំណុចសំខាន់ប្រចាំខែ',
                'body_en' => 'Welcome to our monthly issue.',
                'body_km' => 'សូមស្វាគមន៍មកកាន់ព្រឹត្តិបត្រប្រចាំខែរបស់យើង។',
                'book_ids' => [$book->id],
            ]);

        $createRes->assertStatus(201)
            ->assertJson([
                'subject_en' => 'Monthly Highlights',
                'status' => 'draft',
            ]);

        $campaignId = $createRes->json('id');

        // 2. Show campaign
        $this->actingAsAdmin($admin)
            ->getJson('/api/v1/staff/newsletter/campaigns/'.$campaignId)
            ->assertStatus(200)
            ->assertJsonPath('campaign.id', $campaignId)
            ->assertJsonCount(1, 'books');

        // 3. Update campaign
        $this->actingAsAdmin($admin)
            ->putJson('/api/v1/staff/newsletter/campaigns/'.$campaignId, [
                'subject_en' => 'Monthly Highlights Updated',
                'body_en' => 'Updated text.',
                'book_ids' => [],
            ])
            ->assertStatus(200)
            ->assertJsonPath('subject_en', 'Monthly Highlights Updated');

        // 4. Test send to admin's own email
        Notification::fake();
        $this->actingAsAdmin($admin)
            ->postJson('/api/v1/staff/newsletter/campaigns/'.$campaignId.'/test')
            ->assertStatus(200)
            ->assertJson(['message' => 'Test email sent to admin@bookly.test']);

        Notification::assertSentTo(
            new AnonymousNotifiable,
            NewsletterCampaignNotification::class
        );
    }

    public function test_admin_campaign_send_creates_outbox_rows_for_confirmed_subscribers_with_locale_fallback(): void
    {
        $admin = StaffUser::factory()->admin()->withTwoFactor()->create(['email' => 'editor@bookly.test']);

        // 2 confirmed subscribers (1 en, 1 km) and 1 pending and 1 unsubscribed
        NewsletterSubscriber::create([
            'email' => 'en_user@example.com',
            'locale' => 'en',
            'status' => 'confirmed',
            'confirmed_at' => now(),
            'unsubscribe_token_hash' => NewsletterSubscriber::hashToken('token1'),
        ]);

        NewsletterSubscriber::create([
            'email' => 'km_user@example.com',
            'locale' => 'km',
            'status' => 'confirmed',
            'confirmed_at' => now(),
            'unsubscribe_token_hash' => NewsletterSubscriber::hashToken('token2'),
        ]);

        NewsletterSubscriber::create([
            'email' => 'pending_user@example.com',
            'locale' => 'en',
            'status' => 'pending',
            'unsubscribe_token_hash' => NewsletterSubscriber::hashToken('token3'),
        ]);

        NewsletterSubscriber::create([
            'email' => 'unsub_user@example.com',
            'locale' => 'en',
            'status' => 'unsubscribed',
            'unsubscribe_token_hash' => NewsletterSubscriber::hashToken('token4'),
        ]);

        // Campaign with both en and km
        $campaign = NewsletterCampaign::create([
            'subject_en' => 'English Subject',
            'subject_km' => 'ចំណងជើងខ្មែរ',
            'body_en' => 'English Body',
            'body_km' => 'ខ្លឹមសារខ្មែរ',
            'status' => 'draft',
            'created_by_staff_id' => $admin->id,
        ]);

        $res = $this->actingAsAdmin($admin)
            ->postJson('/api/v1/staff/newsletter/campaigns/'.$campaign->id.'/send');

        $res->assertStatus(200)
            ->assertJson([
                'recipients_total' => 2,
                'daily_limit' => 60,
                'estimated_days' => 1,
            ])
            ->assertJsonPath('campaign.status', 'sent');

        // Marketing outbox rows: exactly 2 rows created
        $this->assertSame(2, MarketingOutbox::where('kind', 'newsletter')->count());

        $enRow = MarketingOutbox::where('email', 'en_user@example.com')->first();
        $this->assertNotNull($enRow);
        $this->assertSame('en', $enRow->locale);
        $this->assertSame('English Subject', $enRow->payload['subject']);
        $this->assertSame('English Body', $enRow->payload['body']);

        $kmRow = MarketingOutbox::where('email', 'km_user@example.com')->first();
        $this->assertNotNull($kmRow);
        $this->assertSame('km', $kmRow->locale);
        $this->assertSame('ចំណងជើងខ្មែរ', $kmRow->payload['subject']);
        $this->assertSame('ខ្លឹមសារខ្មែរ', $kmRow->payload['body']);

        // Cannot send twice
        $this->actingAsAdmin($admin)
            ->postJson('/api/v1/staff/newsletter/campaigns/'.$campaign->id.'/send')
            ->assertStatus(422)
            ->assertJson(['message' => 'This campaign has already been sent or is currently sending.']);

        // Cannot edit or delete sent campaign
        $this->actingAsAdmin($admin)
            ->putJson('/api/v1/staff/newsletter/campaigns/'.$campaign->id, [
                'subject_en' => 'Change',
                'body_en' => 'Change',
            ])
            ->assertStatus(422);

        $this->actingAsAdmin($admin)
            ->deleteJson('/api/v1/staff/newsletter/campaigns/'.$campaign->id)
            ->assertStatus(422);
    }
}
