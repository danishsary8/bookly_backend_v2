<?php

namespace Tests\Feature\Credit;

use App\Models\Customer;
use App\Services\Credit\CreditWallet;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CreditWalletTest extends TestCase
{
    use RefreshDatabase;

    public function test_partial_spending_and_retry_preserve_the_balance_and_journal(): void
    {
        $customer = Customer::factory()->create();
        $wallet = app(CreditWallet::class);
        $this->assertSame(2000, $wallet->move($customer, 2000, 'gift:1'));
        $this->assertSame(1250, $wallet->move($customer, -750, 'order:1'));
        $this->assertSame(1250, $wallet->move($customer, -750, 'order:1'));
        $this->assertDatabaseHas('credit_wallets', ['customer_id' => $customer->id, 'balance_cents' => 1250]);
        $this->assertDatabaseCount('credit_ledger_entries', 2);
    }

    public function test_insufficient_credit_cannot_create_a_negative_balance(): void
    {
        $customer = Customer::factory()->create();
        $wallet = app(CreditWallet::class);
        $wallet->move($customer, 500, 'gift:1');
        try {
            $wallet->move($customer, -501, 'order:1');
            $this->fail('An overdraft must fail.');
        } catch (DomainException) {
            $this->assertDatabaseHas('credit_wallets', ['customer_id' => $customer->id, 'balance_cents' => 500]);
            $this->assertDatabaseCount('credit_ledger_entries', 1);
        }
    }

    public function test_outer_transaction_rollback_reverts_the_movement(): void
    {
        $customer = Customer::factory()->create();
        try {
            DB::transaction(function () use ($customer) {
                app(CreditWallet::class)->move($customer, 500, 'gift:1');
                throw new DomainException('abort');
            });
        } catch (DomainException) {
            $this->assertDatabaseMissing('credit_wallets', ['customer_id' => $customer->id]);
            $this->assertDatabaseCount('credit_ledger_entries', 0);
        }
    }
}
