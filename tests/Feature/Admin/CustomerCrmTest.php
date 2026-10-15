<?php

namespace Tests\Feature\Admin;

use App\Models\Customer;
use App\Models\CustomerNote;
use App\Models\StaffUser;
use App\Notifications\OtpCodeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\ActsAsStaff;
use Tests\Concerns\BuildsOrders;
use Tests\TestCase;

class CustomerCrmTest extends TestCase
{
    use ActsAsStaff, BuildsOrders, RefreshDatabase;

    private Customer $customer;

    private string $customerToken;

    private string $staffToken;

    private string $adminToken;

    private StaffUser $staffUser;

    private StaffUser $adminUser;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('resend-verification:customer:1');

        $this->customer = Customer::factory()->create([
            'email' => 'crm_test@example.com',
            'email_verified_at' => null,
        ]);
        $this->customerToken = $this->customer->createToken('test', ['customer'])->plainTextToken;

        $staffFactory = StaffUser::factory()->withTwoFactor();
        $this->staffUser = $staffFactory->create(['name' => 'Support Staff']);
        $this->staffToken = $this->staffUser->createToken('test', $this->staffUser->tokenAbilities())->plainTextToken;

        $adminFactory = StaffUser::factory()->withTwoFactor()->admin();
        $this->adminUser = $adminFactory->create(['name' => 'Super Admin']);
        $this->adminToken = $this->adminUser->createToken('test', $this->adminUser->tokenAbilities())->plainTextToken;
    }

    public function test_guest_and_customer_cannot_access_staff_customer_crm_endpoints(): void
    {
        $id = $this->customer->id;
        $note = CustomerNote::create([
            'customer_id' => $this->customer->id,
            'staff_user_id' => $this->staffUser->id,
            'body' => 'Permission check note',
        ]);

        // Guest
        $this->getJson("/api/v1/staff/customers/{$id}/notes")->assertUnauthorized();
        $this->postJson("/api/v1/staff/customers/{$id}/notes", ['body' => 'Test'])->assertUnauthorized();
        $this->deleteJson("/api/v1/staff/customer-notes/{$note->id}")->assertUnauthorized();
        $this->putJson("/api/v1/staff/customers/{$id}/tags", ['tags' => ['vip']])->assertUnauthorized();
        $this->getJson('/api/v1/staff/customer-tags')->assertUnauthorized();
        $this->postJson("/api/v1/staff/customers/{$id}/resend-verification")->assertUnauthorized();

        // Customer
        $this->asToken($this->customerToken)->getJson("/api/v1/staff/customers/{$id}/notes")->assertForbidden();
        $this->asToken($this->customerToken)->postJson("/api/v1/staff/customers/{$id}/notes", ['body' => 'Test'])->assertForbidden();
        $this->asToken($this->customerToken)->deleteJson("/api/v1/staff/customer-notes/{$note->id}")->assertForbidden();
        $this->asToken($this->customerToken)->putJson("/api/v1/staff/customers/{$id}/tags", ['tags' => ['vip']])->assertForbidden();
        $this->asToken($this->customerToken)->getJson('/api/v1/staff/customer-tags')->assertForbidden();
        $this->asToken($this->customerToken)->postJson("/api/v1/staff/customers/{$id}/resend-verification")->assertForbidden();
    }

    public function test_customer_notes_crud_and_permissions(): void
    {
        $otherStaff = StaffUser::factory()->withTwoFactor()->create(['name' => 'Other Staff']);
        $otherStaffToken = $otherStaff->createToken('test', $otherStaff->tokenAbilities())->plainTextToken;

        // Validation errors
        $this->asToken($this->staffToken)
            ->postJson("/api/v1/staff/customers/{$this->customer->id}/notes", ['body' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['body']);

        $this->asToken($this->staffToken)
            ->postJson("/api/v1/staff/customers/{$this->customer->id}/notes", ['body' => str_repeat('a', 2001)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['body']);

        // Staff creates note
        $res = $this->asToken($this->staffToken)
            ->postJson("/api/v1/staff/customers/{$this->customer->id}/notes", [
                'body' => 'Customer called to ask about hardcover restock.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.body', 'Customer called to ask about hardcover restock.')
            ->assertJsonPath('data.author.name', 'Support Staff');

        $noteId = $res->json('data.id');

        // Audit log verified
        $this->assertDatabaseHas('admin_audit_logs', [
            'staff_user_id' => $this->staffUser->id,
            'action' => 'customer_note.created',
        ]);

        // List notes
        $this->asToken($this->staffToken)
            ->getJson("/api/v1/staff/customers/{$this->customer->id}/notes")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $noteId);

        // Other staff cannot delete
        $this->asToken($otherStaffToken)
            ->deleteJson("/api/v1/staff/customer-notes/{$noteId}")
            ->assertForbidden();

        // Admin CAN delete
        $this->asToken($this->adminToken)
            ->deleteJson("/api/v1/staff/customer-notes/{$noteId}")
            ->assertNoContent();

        $this->assertDatabaseMissing('customer_notes', ['id' => $noteId]);

        // Author staff can delete own note
        $note2 = CustomerNote::create([
            'customer_id' => $this->customer->id,
            'staff_user_id' => $this->staffUser->id,
            'body' => 'Another note',
        ]);

        $this->asToken($this->staffToken)
            ->deleteJson("/api/v1/staff/customer-notes/{$note2->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('customer_notes', ['id' => $note2->id]);
    }

    public function test_customer_notes_are_never_returned_to_customer(): void
    {
        CustomerNote::create([
            'customer_id' => $this->customer->id,
            'staff_user_id' => $this->staffUser->id,
            'body' => 'Secret internal note',
        ]);

        $res = $this->asToken($this->customerToken)
            ->getJson('/api/v1/me')
            ->assertOk();

        $this->assertArrayNotHasKey('notes', $res->json('data'));
        $this->assertStringNotContainsString('Secret internal note', $res->getContent());
    }

    public function test_customer_tags_crud_and_case_insensitive_matching(): void
    {
        $customer2 = Customer::factory()->create(['name' => 'Second Customer']);

        // Set tags on customer 1 with trailing whitespace and multiple spaces
        $res1 = $this->asToken($this->staffToken)
            ->putJson("/api/v1/staff/customers/{$this->customer->id}/tags", [
                'tags' => ['  VIP  ', 'book   club', 'Frequent-Reader'],
            ])
            ->assertOk();

        $this->assertEqualsCanonicalizing(['VIP', 'book club', 'Frequent-Reader'], $res1->json('tags'));

        // Tag "VIP" was created
        $this->assertDatabaseHas('customer_tags', ['name' => 'VIP']);

        // Update customer 2 using lowercase "vip" -> reuses "VIP" spelling
        $res2 = $this->asToken($this->staffToken)
            ->putJson("/api/v1/staff/customers/{$customer2->id}/tags", [
                'tags' => ['vip', 'wholesale'],
            ])
            ->assertOk();

        $this->assertEqualsCanonicalizing(['VIP', 'wholesale'], $res2->json('tags'));

        // Audit log created for tags update
        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => 'customer.updated_tags',
        ]);

        // Max 10 tags validation
        $this->asToken($this->staffToken)
            ->putJson("/api/v1/staff/customers/{$this->customer->id}/tags", [
                'tags' => array_map(fn ($i) => "tag-{$i}", range(1, 11)),
            ])
            ->assertUnprocessable();

        // Tag suggestions
        $suggestions = $this->asToken($this->staffToken)
            ->getJson('/api/v1/staff/customer-tags?q=vip')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $suggestions);
        $this->assertSame('VIP', $suggestions[0]['name']);
        $this->assertSame(2, $suggestions[0]['count']);

        // Customers list filter by ?tag=
        $listRes = $this->asToken($this->staffToken)
            ->getJson('/api/v1/staff/customers?tag=VIP')
            ->assertOk();

        $names = collect($listRes->json('data'))->pluck('name')->all();
        $this->assertContains($this->customer->name, $names);
        $this->assertContains($customer2->name, $names);

        // Tags visible on customer resource
        $firstItem = $listRes->json('data.0');
        $this->assertArrayHasKey('tags', $firstItem);
    }

    public function test_lifetime_spent_and_order_total_is_admin_only(): void
    {
        $variant = $this->physical('50.00');
        $this->deliveredOrder($this->customer, [[$variant, 1]]);

        // Staff sees customer detail: NO lifetime_spent_usd, NO recent_orders[0].total_usd
        $staffRes = $this->asToken($this->staffToken)
            ->getJson("/api/v1/staff/customers/{$this->customer->id}")
            ->assertOk();

        $staffData = $staffRes->json('data');
        $this->assertArrayHasKey('stats', $staffData);
        $this->assertArrayNotHasKey('lifetime_spent_usd', $staffData['stats']);
        $this->assertNotEmpty($staffData['recent_orders']);
        $this->assertArrayNotHasKey('total_usd', $staffData['recent_orders'][0]);

        // Admin sees customer detail: lifetime_spent_usd IS present, total_usd IS present
        $adminRes = $this->asToken($this->adminToken)
            ->getJson("/api/v1/staff/customers/{$this->customer->id}")
            ->assertOk();

        $adminData = $adminRes->json('data');
        $this->assertArrayHasKey('stats', $adminData);
        $this->assertSame('50.00', $adminData['stats']['lifetime_spent_usd']);
        $this->assertNotEmpty($adminData['recent_orders']);
        $this->assertSame('50.00', $adminData['recent_orders'][0]['total_usd']);
    }

    public function test_resend_verification_rules_and_rate_limits(): void
    {
        Notification::fake();

        // Customer without email
        $noEmailCust = Customer::factory()->create([
            'email' => null,
            'phone' => '+85512345678',
            'phone_e164' => '+85512345678',
        ]);
        $this->asToken($this->staffToken)
            ->postJson("/api/v1/staff/customers/{$noEmailCust->id}/resend-verification")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This customer has no email address.');

        // Customer with verified email
        $verifiedCust = Customer::factory()->create([
            'email' => 'verified@example.com',
            'email_verified_at' => now(),
        ]);
        $this->asToken($this->staffToken)
            ->postJson("/api/v1/staff/customers/{$verifiedCust->id}/resend-verification")
            ->assertUnprocessable()
            ->assertJsonPath('message', "This customer's email is already verified.");

        // Unverified customer: send 1, 2, 3 succeed
        RateLimiter::clear("resend-verification:customer:{$this->customer->id}");

        $this->asToken($this->staffToken)
            ->postJson("/api/v1/staff/customers/{$this->customer->id}/resend-verification")
            ->assertOk()
            ->assertJsonPath('message', 'Verification email sent.');

        Notification::assertSentTo($this->customer, OtpCodeNotification::class);

        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => 'customer.resend_verification',
        ]);

        $this->asToken($this->staffToken)
            ->postJson("/api/v1/staff/customers/{$this->customer->id}/resend-verification")
            ->assertOk();

        $this->asToken($this->staffToken)
            ->postJson("/api/v1/staff/customers/{$this->customer->id}/resend-verification")
            ->assertOk();

        // 4th attempt exceeds 3/hour rate limit
        $this->asToken($this->staffToken)
            ->postJson("/api/v1/staff/customers/{$this->customer->id}/resend-verification")
            ->assertStatus(429);
    }
}
