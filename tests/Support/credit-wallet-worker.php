<?php

use App\Models\Customer;
use App\Services\Credit\CreditWallet;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    app(CreditWallet::class)->move(Customer::findOrFail((int) $argv[1]), -1000, $argv[2]);
    echo 'spent';
} catch (DomainException) {
    echo 'insufficient';
}
