<?php

namespace Tests\Feature;

use App\Enums\BookFormat;
use App\Enums\CouponType;
use App\Models\Author;
use App\Models\Book;
use App\Models\BookVariant;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\OrderItem;
use App\Models\Series;
use App\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ModelRelationshipsTest extends TestCase
{
    use RefreshDatabase;

    public function test_book_catalog_relationships(): void
    {
        $series = Series::factory()->create();
        $book = Book::factory()->create(['series_id' => $series->id, 'series_order' => 1]);
        $book->authors()->attach(Author::factory()->count(2)->create());
        $book->categories()->attach(Category::factory()->create());
        BookVariant::factory()->for($book)->create();
        BookVariant::factory()->for($book)->format(BookFormat::Ebook)->create();

        $book->refresh();
        $this->assertCount(2, $book->authors);
        $this->assertCount(1, $book->categories);
        $this->assertCount(2, $book->variants);
        $this->assertTrue($book->series->is($series));
        $this->assertNotNull($book->publisher);
        $this->assertEqualsCanonicalizing(['paperback', 'ebook'], $book->variants->map(fn ($v) => $v->format->value)->all());
    }

    public function test_passwords_are_hashed_into_password_hash_column(): void
    {
        $customer = Customer::factory()->create(['password_hash' => 'secret-pass']);
        $staff = StaffUser::factory()->create(['password_hash' => 'secret-pass']);

        $this->assertTrue(Hash::check('secret-pass', $customer->getAuthPassword()));
        $this->assertTrue(Hash::check('secret-pass', $staff->getAuthPassword()));
        $this->assertArrayNotHasKey('password_hash', $customer->toArray());
    }

    public function test_staff_two_factor_secret_is_encrypted_at_rest(): void
    {
        $staff = StaffUser::factory()->withTwoFactor('JBSWY3DPEHPK3PXP')->create();

        $raw = \DB::table('staff_users')->where('id', $staff->id)->value('two_factor_secret');
        $this->assertNotSame('JBSWY3DPEHPK3PXP', $raw);
        $this->assertSame('JBSWY3DPEHPK3PXP', $staff->fresh()->two_factor_secret);
    }

    public function test_order_item_chain(): void
    {
        $item = OrderItem::factory()->create();

        $this->assertNotNull($item->order->customer);
        $this->assertNotNull($item->variant->book);
    }

    public function test_coupon_discount_rules(): void
    {
        $percent = Coupon::factory()->create(['type' => CouponType::Percentage, 'value' => '10.00']);
        $fixed = Coupon::factory()->create(['type' => CouponType::Fixed, 'value' => '50.00', 'min_order_amount' => '20.00']);

        $this->assertSame(1999, $percent->discountCentsFor(19990));
        $this->assertSame(3000, $fixed->discountCentsFor(3000)); // capped at subtotal
        $this->assertNotNull($fixed->unusableReason('19.99'));
        $this->assertNull($fixed->unusableReason('20.00'));
    }
}
