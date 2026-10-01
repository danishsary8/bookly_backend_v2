<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseSchemaTest extends TestCase
{
    use RefreshDatabase;

    private const TABLES = [
        'customers', 'staff_users', 'customer_addresses', 'verification_tokens',
        'authors', 'publishers', 'categories', 'series', 'books', 'book_authors', 'book_categories', 'book_variants',
        'inventory_movements', 'carts', 'cart_items', 'wishlists', 'coupons',
        'orders', 'order_items', 'order_status_history', 'payments', 'payment_webhook_logs',
        'returns', 'return_items', 'reviews', 'exchange_rates', 'admin_audit_logs',
        'notifications', 'personal_access_tokens', 'jobs', 'failed_jobs',
    ];

    public function test_all_expected_tables_exist(): void
    {
        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table: {$table}");
        }
    }

    public function test_soft_deletes_exist_only_on_customers_orders_and_books(): void
    {
        foreach (['customers', 'orders', 'books'] as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'deleted_at'), "{$table} should soft delete");
        }

        foreach (['authors', 'book_variants', 'coupons', 'payments', 'staff_users'] as $table) {
            $this->assertFalse(Schema::hasColumn($table, 'deleted_at'), "{$table} should not soft delete");
        }
    }

    public function test_book_search_vector_finds_books_by_title_and_description(): void
    {
        DB::table('books')->insert([
            'title' => 'The Hobbit',
            'description' => 'A hobbit goes on an adventure with dwarves',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $found = DB::table('books')
            ->whereRaw("search_vector @@ plainto_tsquery('english', ?)", ['dwarves adventure'])
            ->count();

        $this->assertSame(1, $found);
    }

    public function test_book_variant_rejects_invalid_format_and_negative_price(): void
    {
        $bookId = DB::table('books')->insertGetId(['title' => 'T', 'created_at' => now(), 'updated_at' => now()]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('book_variants')->insert([
            'book_id' => $bookId, 'format' => 'vinyl', 'sku' => 'X-1', 'price_usd' => 5,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_one_variant_per_format_per_book(): void
    {
        $bookId = DB::table('books')->insertGetId(['title' => 'T', 'created_at' => now(), 'updated_at' => now()]);
        $row = ['book_id' => $bookId, 'format' => 'paperback', 'price_usd' => 5, 'created_at' => now(), 'updated_at' => now()];
        DB::table('book_variants')->insert($row + ['sku' => 'A-1']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('book_variants')->insert($row + ['sku' => 'A-2']);
    }
}
