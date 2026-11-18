<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $hasVector = (bool) DB::table('pg_available_extensions')
            ->where('name', 'vector')
            ->exists();

        if ($hasVector) {
            DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
        }

        Schema::create('book_embeddings', function (Blueprint $table) use ($hasVector) {
            $table->id();
            $table->foreignId('book_id')->unique()->constrained('books')->cascadeOnDelete();

            if ($hasVector) {
                $table->addColumn('vector', 'embedding', ['dimensions' => 768]);
            } else {
                $table->jsonb('embedding');
            }

            $table->string('model', 50);
            $table->char('text_hash', 64);
            $table->timestamp('embedded_at');
            $table->timestamps();
        });

        if ($hasVector) {
            DB::statement('CREATE INDEX book_embeddings_embedding_hnsw_idx ON book_embeddings USING hnsw (embedding vector_cosine_ops)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS book_embeddings_embedding_hnsw_idx');
        Schema::dropIfExists('book_embeddings');
    }
};
