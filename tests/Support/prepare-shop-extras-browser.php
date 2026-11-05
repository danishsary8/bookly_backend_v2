<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\Book;
use App\Models\BookVariant;
use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Gift;
use App\Models\Offer;
use App\Models\Order;
use App\Models\ReadingList;
use App\Services\Cart\CartService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

$database = 'bookshop_v2_extras_browser_test';
if (! app()->environment('testing') || config('database.connections.pgsql.database') !== $database
    || ! in_array(config('database.connections.pgsql.host'), ['127.0.0.1', 'localhost', '::1'], true)) {
    throw new RuntimeException('Only the dedicated local browser test database is allowed.');
}
config(['database.connections.pgsql.database' => 'postgres']);
DB::purge('pgsql');
if (! DB::selectOne('SELECT 1 FROM pg_database WHERE datname = ?', [$database])) {
    DB::statement('CREATE DATABASE bookshop_v2_extras_browser_test');
}
config(['database.connections.pgsql.database' => $database, 'services.ops_alerts.token' => null, 'services.telegram_bot.token' => null]);
DB::purge('pgsql');
Artisan::call('migrate:fresh', ['--force' => true]);
$customer = Customer::factory()->create(['name' => 'Danish Browser', 'email' => 'phase5@example.test']);
CustomerAddress::factory()->create(['customer_id' => $customer->id, 'is_default' => true, 'city' => 'Phnom Penh', 'state' => null]);
$category = Category::factory()->create(['name' => 'Fiction', 'slug' => 'fiction']);
$books = [];
for ($i = 1; $i <= 14; $i++) {
    $book = Book::factory()->create(['title' => 'Browser book '.$i, 'description' => 'A local browser verification fixture.']);
    $book->categories()->attach($category);
    $variant = BookVariant::factory()->create(['book_id' => $book->id, 'price_usd' => '12.50', 'stock_quantity' => $i === 1 ? 3 : 20, 'cover_image_url' => null]);
    $books[] = $book;
    if ($i === 1) {
        app(CartService::class)->add($customer, $variant->id, 1);
        $order = Order::factory()->create(['customer_id' => $customer->id, 'status' => 'delivered']);
        $order->items()->create(['book_variant_id' => $variant->id, 'quantity' => 6, 'unit_price' => '12.50', 'subtotal' => '75.00']);
    }
    if ($i <= 2) {
        BookVariant::factory()->create(['book_id' => $book->id, 'format' => 'ebook', 'price_usd' => (string) $i, 'cover_image_url' => null]);
    }
    if ($i === 14) {
        $variant->update(['release_date' => today()->addDays(10)]);
    }
}
$gift = Gift::create(['name_en' => 'Highlighter pen', 'name_km' => 'ប៊ិចរំលេច', 'stock' => 10]);
Offer::factory()->create(['type' => 'gift', 'gift_id' => $gift->id, 'percent_off' => 0, 'min_quantity' => 1]);
$list = ReadingList::forceCreate(['customer_id' => $customer->id, 'title' => 'Weekend reading', 'description' => 'Books selected for a quiet weekend.', 'slug' => 'browser-weekend', 'is_public' => true, 'is_staff_pick' => true]);
$list->books()->attach([$books[0]->id => ['position' => 0, 'note' => 'Start here.'], $books[1]->id => ['position' => 1, 'note' => null]]);
echo 'Dedicated local browser fixtures prepared.'.PHP_EOL;
