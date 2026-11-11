<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Promotion pop-ups (owner 2026-11-12): what the shop announces in a pop-up (and as a small reminder in the cart and
 * at checkout) between two dates. It only advertises: the discount itself is the linked automatic offer, if any.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->string('title_en', 120);
            $table->string('title_km', 120)->nullable(); // null: the shop shows title_en in Khmer too
            $table->string('body_en', 500)->nullable();
            $table->string('body_km', 500)->nullable();
            $table->string('image_url', 500)->nullable();
            $table->string('cta_label_en', 40)->nullable(); // null: the shop's own "Shop now"
            $table->string('cta_label_km', 40)->nullable();
            $table->string('cta_url', 255)->default('/books'); // a path inside the shop
            $table->foreignId('offer_id')->nullable()->constrained('offers')->nullOnDelete();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(false); // false = hidden
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotions');
    }
};
