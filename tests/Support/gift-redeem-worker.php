<?php

use App\Models\Customer;
use App\Services\Credit\GiftCode;
use App\Services\Credit\GiftRedemption;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['shop.gift_cards_enabled' => true]);

try {
    app(GiftRedemption::class)->redeem(Customer::findOrFail((int) $argv[1]), app(GiftCode::class)->forId((int) $argv[2]));
    echo 'redeemed';
} catch (DomainException) {
    echo 'unavailable';
}
