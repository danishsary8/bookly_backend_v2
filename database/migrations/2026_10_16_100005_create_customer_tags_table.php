<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_tags', function (Blueprint $table) {
            $table->id();
            $table->string('name', 40);
            $table->timestamps();
        });

        DB::statement('CREATE UNIQUE INDEX customer_tags_lower_name_unique ON customer_tags (LOWER(name))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS customer_tags_lower_name_unique');
        Schema::dropIfExists('customer_tags');
    }
};
