<?php

use App\Models\BookVariant;
use App\Models\Customer;
use App\Models\Order;
use App\Models\StaffUser;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$database = 'bookshop_v2_dashboard_browser_test';
if (! app()->environment('testing') || config('database.connections.pgsql.database') !== $database
    || ! in_array(config('database.connections.pgsql.host'), ['127.0.0.1', 'localhost', '::1'], true)) {
    throw new RuntimeException('Only the dedicated local browser test database is allowed.');
}
config(['database.connections.pgsql.database' => 'postgres']);
DB::purge('pgsql');
if (! DB::selectOne('SELECT 1 FROM pg_database WHERE datname = ?', [$database])) {
    DB::statement('CREATE DATABASE bookshop_v2_dashboard_browser_test');
}
config(['database.connections.pgsql.database' => $database, 'services.ops_alerts.token' => null, 'services.telegram_bot.token' => null]);
DB::purge('pgsql');
Artisan::call('migrate:fresh', ['--force' => true]);
BookVariant::factory()->create(['stock_quantity' => 2, 'low_stock_threshold' => 5]);
$customer = Customer::factory()->create();
Order::factory()->create(['customer_id' => $customer->id, 'status' => 'pending']);
StaffUser::factory()->admin()->withTwoFactor()->create(['name' => 'Dashboard Staff', 'email' => 'dashboard-staff@example.test']);
echo 'Dedicated local dashboard fixtures prepared.'.PHP_EOL;
