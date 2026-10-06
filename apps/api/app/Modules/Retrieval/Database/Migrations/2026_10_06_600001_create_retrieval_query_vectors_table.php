<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A vector for each distinct thing shoppers typed into the search, per shop and model, so
        // the same query never costs twice. The text is kept normalized (no case, no niqqud).
        Schema::create('retrieval_query_vectors', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('embedding_model', 120);
            $table->string('text_hash', 64);
            $table->string('text', 300);
            $table->unsignedSmallInteger('dimensions');
            $table->unsignedInteger('uses')->default(1);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['shop_id', 'embedding_model', 'text_hash']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE retrieval_query_vectors ADD COLUMN embedding vector NOT NULL');
        } else {
            Schema::table('retrieval_query_vectors', fn (Blueprint $table) => $table->text('embedding')->default(''));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('retrieval_query_vectors');
    }
};
