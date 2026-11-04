<?php

use App\Support\StarterCategories;
use Illuminate\Database\Migrations\Migration;

/** Adds the starter category tree; keeps every existing category (see App\Support\StarterCategories). */
return new class extends Migration
{
    public function up(): void
    {
        // Tests build their own categories; the ones about the tree install it themselves.
        if (! app()->environment('testing')) {
            StarterCategories::install();
        }
    }

    public function down(): void
    {
        // Nothing: the categories may already hold books, and staff may have edited them.
    }
};
