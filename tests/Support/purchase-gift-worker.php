<?php

use App\Models\Customer;
use App\Services\Orders\CheckoutService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

// Separate PostgreSQL connection for the last-gift race. No external services may run in this worker.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
Http::fake();
Mail::fake();
Notification::fake();
config(['services.ops_alerts.token' => null, 'services.telegram.bot_token' => null]);
$customer = Customer::findOrFail((int) $argv[1]);
$result = app(CheckoutService::class)->place($customer, (int) $argv[2], null, $argv[3]);
echo $result['order']->free_gift === null ? 'without-gift' : 'with-gift';
