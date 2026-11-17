<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner 2026-11-17: gift cards and the checkout gift note are no longer part of the shop. Drops their tables and the
 * two order columns. Free gifts with offers (gifts, order free_gift) stay. `down()` brings the empty structure back
 * by re-running the original migrations, so a rollback restores the schema (not the data).
 */
return new class extends Migration
{
    private const ORIGINAL = [
        '2026_11_09_000002_create_gift_cards.php',
        '2026_11_09_000003_create_gift_redemption_attempts.php',
        '2026_11_09_000004_add_gift_card_payments.php',
        '2026_11_09_000006_add_gift_delivery_state.php',
        '2026_11_09_000008_add_gift_payment_check_time.php',
    ];

    public function up(): void
    {
        Schema::dropIfExists('gift_card_payments');
        Schema::dropIfExists('gift_redemption_attempts');
        Schema::dropIfExists('gift_cards');

        if (Schema::hasColumn('orders', 'is_gift')) {
            Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['is_gift', 'gift_message']));
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('orders', 'is_gift')) {
            (require database_path('migrations/2026_11_05_100002_add_gift_note_to_orders.php'))->up();
        }
        if (! Schema::hasTable('gift_cards')) {
            foreach (self::ORIGINAL as $file) {
                (require database_path('migrations/'.$file))->up();
            }
        }
    }
};
