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
        $isPgsql = DB::connection()->getDriverName() === 'pgsql';

        if ($isPgsql) {
            DB::statement('CREATE EXTENSION IF NOT EXISTS vector;');
        }

        Schema::create('embeddings', function (Blueprint $table) use ($isPgsql) {
            $table->id();
            $table->foreignId('procedure_id')->nullable()->constrained()->onDelete('cascade');
            $table->text('content')->nullable();
            
            // Define json type embedding directly during creation for non-pgsql (SQLite)
            if (!$isPgsql) {
                $table->json('embedding')->nullable();
            }

            $table->timestamps();
        });

        // Add pgvector extension column and HNSW index if on PostgreSQL
        if ($isPgsql) {
            DB::statement('ALTER TABLE embeddings ADD COLUMN embedding vector(1536);');
            DB::statement('CREATE INDEX embeddings_vector_hnsw_idx ON embeddings USING hnsw (embedding vector_cosine_ops);');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('embeddings');
    }
};