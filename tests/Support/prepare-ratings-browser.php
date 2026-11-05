<?php

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderRating;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$database = 'bookshop_v2_ratings_browser_test';
if (! app()->environment('testing') || config('database.connections.pgsql.database') !== $database
    || ! in_array(config('database.connections.pgsql.host'), ['127.0.0.1', 'localhost', '::1'], true)) {
    throw new RuntimeException('Only the dedicated local browser test database is allowed.');
}
config(['database.connections.pgsql.database' => 'postgres']);
DB::purge('pgsql');
if (! DB::selectOne('SELECT 1 FROM pg_database WHERE datname = ?', [$database])) {
    DB::statement('CREATE DATABASE bookshop_v2_ratings_browser_test');
}
config(['database.connections.pgsql.database' => $database, 'services.ops_alerts.token' => null, 'services.telegram_bot.token' => null]);
DB::purge('pgsql');
Artisan::call('migrate:fresh', ['--force' => true]);
$customer = Customer::factory()->create(['name' => 'Danish Ratings', 'email' => 'ratings@example.test']);
for ($i = 0; $i < 17; $i++) {
    $order = Order::factory()->create(['customer_id' => $customer->id, 'status' => 'delivered']);
    if ($i < 9) {
        OrderRating::forceCreate(['customer_id' => $customer->id, 'order_id' => $order->id, 'stars' => 5]);
    }
}
echo 'Dedicated ratings fixtures prepared.'.PHP_EOL;
