<?php

namespace Tests\Feature\Credit;

use App\Models\Customer;
use App\Services\Credit\GiftCode;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class GiftRedemptionConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_one_card_can_only_be_redeemed_by_one_of_two_concurrent_customers(): void
    {
        $db = config('database.connections.pgsql');
        $this->assertContains($db['host'], ['127.0.0.1', 'localhost', '::1']);
        $this->assertStringEndsWith('_test', $db['database']);
        $buyer = Customer::factory()->create();
        $card = DB::table('gift_cards')->insertGetId([
            'buyer_customer_id' => $buyer->id, 'recipient_name' => 'Reader', 'recipient_email' => 'reader@example.test',
            'send_on' => today(), 'amount_cents' => 1000, 'status' => 'active', 'paid_at' => now(),
            'expires_at' => now()->addYear(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $codes = app(GiftCode::class);
        $code = $codes->forId($card);
        DB::table('gift_cards')->where('id', $card)->update(['code_hash' => $codes->fingerprint($code), 'last_four' => substr($code, -4)]);
        $env = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_URL' => '',
            'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => $db['database'],
            'DB_USERNAME' => $db['username'], 'DB_PASSWORD' => $db['password'], 'CACHE_STORE' => 'array'];
        $workers = [];
        foreach (range(1, 2) as $unused) {
            $customer = Customer::factory()->create();
            $workers[] = new Process([PHP_BINARY, base_path('tests/Support/gift-redeem-worker.php'), (string) $customer->id, (string) $card], base_path(), $env, timeout: 30);
        }
        foreach ($workers as $worker) {
            $worker->start();
        }
        $results = [];
        foreach ($workers as $worker) {
            $worker->wait();
            $this->assertTrue($worker->isSuccessful(), 'Gift redemption worker failed');
            $results[] = trim($worker->getOutput());
        }
        sort($results);
        $this->assertSame(['redeemed', 'unavailable'], $results);
        $this->assertDatabaseCount('credit_ledger_entries', 1);
        $this->assertSame(1000, (int) DB::table('credit_wallets')->sum('balance_cents'));
    }
}
