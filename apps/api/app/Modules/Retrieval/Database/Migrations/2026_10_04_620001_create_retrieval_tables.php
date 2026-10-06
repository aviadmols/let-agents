<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $pgvector = DB::connection()->getDriverName() === 'pgsql';

        if ($pgvector) {
            DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
        }

        // What a shop's products, pages, posts and purchases say, cut into pieces small enough to
        // find by meaning. Built in code from the catalogue and the orders; nothing here is a
        // source of truth, so a piece is replaced or removed whenever its source changes.
        Schema::create('retrieval_chunks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('source', 30);
            $table->string('source_id', 64);
            $table->string('external_id', 64)->nullable();
            $table->string('title', 500);
            $table->unsignedSmallInteger('position');
            $table->text('text');
            $table->string('text_hash', 64);
            // The model that made the vector, so vectors of different models are never compared
            // and a change of model is a rebuild rather than a silent mix.
            $table->string('embedding_model', 120)->nullable();
            $table->unsignedSmallInteger('dimensions')->nullable();
            $table->timestamp('embedded_at')->nullable();
            $table->timestamps();

            $table->unique(['shop_id', 'source', 'source_id', 'position']);
            $table->index(['shop_id', 'embedding_model', 'source']);
        });

        // pgvector without a fixed size, because the model is a setting. On SQLite (local and
        // tests) the same "[0.1,0.2]" literal is kept as text and compared in PHP.
        if ($pgvector) {
            DB::statement('ALTER TABLE retrieval_chunks ADD COLUMN embedding vector');
        } else {
            Schema::table('retrieval_chunks', fn (Blueprint $table) => $table->text('embedding')->nullable());
        }

        // One request to the matching model per product: what code offered it, what it chose,
        // what it cost. The input hash lets an unchanged product skip the model next night.
        Schema::create('retrieval_match_requests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignUlid('product_id')->constrained('catalog_products')->cascadeOnDelete();
            $table->string('status', 20);
            $table->string('input_hash', 64);
            $table->json('candidates');
            $table->json('answer')->nullable();
            $table->string('error', 200)->nullable();
            $table->string('provider', 40)->nullable();
            $table->string('model', 120)->nullable();
            $table->string('prompt_version', 20)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('cost_usd', 10, 6)->default(0);
            $table->timestamp('asked_at')->nullable();
            $table->timestamps();

            $table->unique(['shop_id', 'product_id']);
        });

        // Every pick the model made, with code's verdict on it. Accepted ones become relations
        // (Enrichment reads them); rejected ones stay, with the reason, for the panel.
        Schema::create('retrieval_matches', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignUlid('request_id')->constrained('retrieval_match_requests')->cascadeOnDelete();
            $table->foreignUlid('product_id')->constrained('catalog_products')->cascadeOnDelete();
            $table->foreignUlid('related_product_id')->nullable()->constrained('catalog_products')->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('status', 20);
            $table->string('rejected_because', 40)->nullable();
            $table->unsignedSmallInteger('position');
            $table->string('reason', 300)->nullable();
            $table->json('signals')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'product_id', 'status']);
            $table->index(['shop_id', 'status', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retrieval_matches');
        Schema::dropIfExists('retrieval_match_requests');
        Schema::dropIfExists('retrieval_chunks');
    }
};
