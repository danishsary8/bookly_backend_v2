<?php

namespace Tests\Feature\Offers;

use App\Models\BookVariant;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Gift;
use App\Models\Offer;
use App\Models\Order;
use App\Services\Cart\CartService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PurchaseGiftConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function tearDown(): void
    {
        // DatabaseMigrations rolls back schema after the test; remove this test's durable gift fixtures first.
        Order::query()->update(['free_gift' => null]);
        Offer::where('type', 'gift')->delete();
        parent::tearDown();
    }

    public function test_two_independent_checkouts_compete_for_the_last_gift(): void
    {
        $this->assertContains(config('database.connections.pgsql.host'), ['127.0.0.1', 'localhost', '::1']);
        $this->assertStringEndsWith('_test', config('database.connections.pgsql.database'));
        $gift = Gift::create(['name_en' => 'Pen', 'name_km' => 'ប៊ិច', 'stock' => 1]);
        Offer::factory()->create(['type' => 'gift', 'gift_id' => $gift->id, 'percent_off' => 0, 'min_quantity' => 1]);
        $db = config('database.connections.pgsql');
        $env = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_URL' => '',
            'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => $db['database'],
            'DB_USERNAME' => $db['username'], 'DB_PASSWORD' => $db['password'], 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync'];
        $workers = [];
        for ($i = 0; $i < 2; $i++) {
            $customer = Customer::factory()->create();
            $address = CustomerAddress::factory()->create(['customer_id' => $customer->id]);
            $variant = BookVariant::factory()->create(['stock_quantity' => 10]);
            app(CartService::class)->add($customer, $variant->id, 1);
            $workers[] = new Process([PHP_BINARY, base_path('tests/Support/purchase-gift-worker.php'), (string) $customer->id, (string) $address->id, 'concurrent-gift-'.$i], base_path(), $env, timeout: 30);
        }
        foreach ($workers as $worker) {
            $worker->start();
        }
        $outputs = [];
        foreach ($workers as $worker) {
            $worker->wait();
            // Do not include subprocess stderr: a database error may contain connection details.
            $this->assertTrue($worker->isSuccessful(), 'Checkout worker failed');
            $outputs[] = trim($worker->getOutput());
        }
        sort($outputs);
        $this->assertSame(['with-gift', 'without-gift'], $outputs);
        $this->assertSame(0, $gift->fresh()->stock);
        $this->assertSame(2, Order::count());
        $this->assertSame(1, Order::whereNotNull('free_gift')->count());
    }
}
