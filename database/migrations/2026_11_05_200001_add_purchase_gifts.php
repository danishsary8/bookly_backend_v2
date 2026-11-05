<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gifts', function (Blueprint $table) {
            $table->id();
            $table->string('name_en', 100);
            $table->string('name_km', 100);
            $table->text('image_url')->nullable();
            $table->integer('stock')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        DB::statement('ALTER TABLE gifts ADD CONSTRAINT gifts_stock_check CHECK (stock >= 0)');
        Schema::table('offers', function (Blueprint $table) {
            $table->foreignId('gift_id')->nullable()->constrained('gifts')->restrictOnDelete();
            $table->smallInteger('gift_quantity')->default(1);
            $table->decimal('min_subtotal', 10, 2)->nullable();
        });
        DB::statement('ALTER TABLE offers DROP CONSTRAINT offers_type_check, DROP CONSTRAINT offers_min_quantity_check, DROP CONSTRAINT offers_percent_off_check');
        DB::statement("ALTER TABLE offers ADD CONSTRAINT offers_type_check CHECK (type IN ('buy_n_percent','gift'))");
        DB::statement("ALTER TABLE offers ADD CONSTRAINT offers_min_quantity_check CHECK ((type = 'gift' AND min_quantity BETWEEN 1 AND 20) OR (type = 'buy_n_percent' AND min_quantity BETWEEN 2 AND 20))");
        DB::statement("ALTER TABLE offers ADD CONSTRAINT offers_percent_off_check CHECK ((type = 'gift' AND percent_off = 0 AND gift_id IS NOT NULL) OR (type = 'buy_n_percent' AND percent_off > 0 AND percent_off <= 50))");
        DB::statement('ALTER TABLE offers ADD CONSTRAINT offers_gift_quantity_check CHECK (gift_quantity BETWEEN 1 AND 100), ADD CONSTRAINT offers_min_subtotal_check CHECK (min_subtotal IS NULL OR min_subtotal >= 0)');
        Schema::table('orders', function (Blueprint $table) {
            $table->jsonb('free_gift')->nullable();
        });
    }

    public function down(): void
    {
        // Removing live gift offers would discard history; reverse only on an empty feature dataset.
        if (DB::table('offers')->where('type', 'gift')->exists() || DB::table('orders')->whereNotNull('free_gift')->exists()) {
            throw new RuntimeException('Purchase gifts are in use; keep their snapshots and schema.');
        }
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('free_gift'));
        DB::statement('ALTER TABLE offers DROP CONSTRAINT offers_type_check, DROP CONSTRAINT offers_min_quantity_check, DROP CONSTRAINT offers_percent_off_check, DROP CONSTRAINT offers_gift_quantity_check, DROP CONSTRAINT offers_min_subtotal_check');
        Schema::table('offers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('gift_id');
            $table->dropColumn(['gift_quantity', 'min_subtotal']);
        });
        DB::statement("ALTER TABLE offers ADD CONSTRAINT offers_type_check CHECK (type = 'buy_n_percent'), ADD CONSTRAINT offers_min_quantity_check CHECK (min_quantity BETWEEN 2 AND 20), ADD CONSTRAINT offers_percent_off_check CHECK (percent_off > 0 AND percent_off <= 50)");
        Schema::dropIfExists('gifts');
    }
};
