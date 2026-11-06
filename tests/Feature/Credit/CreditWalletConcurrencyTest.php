<?php

namespace Tests\Feature\Credit;

use App\Models\Customer;
use App\Services\Credit\CreditWallet;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class CreditWalletConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_two_independent_spends_cannot_both_use_the_same_balance(): void
    {
        $db = config('database.connections.pgsql');
        $this->assertContains($db['host'], ['127.0.0.1', 'localhost', '::1']);
        $this->assertStringEndsWith('_test', $db['database']);
        $customer = Customer::factory()->create();
        app(CreditWallet::class)->move($customer, 1000, 'seed:1');
        $env = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_URL' => '',
            'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => $db['database'],
            'DB_USERNAME' => $db['username'], 'DB_PASSWORD' => $db['password'], 'CACHE_STORE' => 'array'];
        $workers = [];
        foreach (['order:1', 'order:2'] as $reference) {
            $workers[] = new Process([PHP_BINARY, base_path('tests/Support/credit-wallet-worker.php'), (string) $customer->id, $reference], base_path(), $env, timeout: 30);
        }
        foreach ($workers as $worker) {
            $worker->start();
        }
        $results = [];
        foreach ($workers as $worker) {
            $worker->wait();
            $this->assertTrue($worker->isSuccessful(), 'Credit worker failed');
            $results[] = trim($worker->getOutput());
        }
        sort($results);
        $this->assertSame(['insufficient', 'spent'], $results);
        $this->assertDatabaseHas('credit_wallets', ['customer_id' => $customer->id, 'balance_cents' => 0]);
        $this->assertDatabaseCount('credit_ledger_entries', 2);
    }
}
