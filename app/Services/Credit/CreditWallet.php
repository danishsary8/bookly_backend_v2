<?php

namespace App\Services\Credit;

use App\Models\Customer;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Monetary movements are idempotent, and the balance and journal change under one row lock. */
class CreditWallet
{
    public function move(Customer $customer, int $amountCents, string $reference): int
    {
        if ($amountCents === 0 || $reference === '' || strlen($reference) > 120) {
            throw new InvalidArgumentException('Invalid credit movement.');
        }

        return DB::transaction(function () use ($customer, $amountCents, $reference) {
            DB::table('credit_wallets')->insertOrIgnore([
                'customer_id' => $customer->id, 'balance_cents' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $wallet = DB::table('credit_wallets')->where('customer_id', $customer->id)->lockForUpdate()->first();
            $prior = DB::table('credit_ledger_entries')->where('credit_wallet_id', $wallet->id)->where('reference', $reference)->first();
            if ($prior) {
                if ((int) $prior->amount_cents !== $amountCents) {
                    throw new DomainException('Credit reference already used for a different amount.');
                }

                return (int) $prior->balance_after_cents;
            }

            $balance = (int) $wallet->balance_cents + $amountCents;
            if ($balance < 0) {
                throw new DomainException('Insufficient credit.');
            }
            DB::table('credit_wallets')->where('id', $wallet->id)->update(['balance_cents' => $balance, 'updated_at' => now()]);
            DB::table('credit_ledger_entries')->insert([
                'credit_wallet_id' => $wallet->id, 'reference' => $reference,
                'amount_cents' => $amountCents, 'balance_after_cents' => $balance, 'created_at' => now(),
            ]);

            return $balance;
        });
    }
}
