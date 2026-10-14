<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A product's missing details asked of the web: the sources a model found with the sentence each
 * rests on, what code could confirm of each, and what a person decided. Nothing here is a fact
 * about the product until a person approves it (ApplyTeamValue). docs/strict-scan.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrichment_web_lookups', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignUlid('product_id')->constrained('catalog_products')->cascadeOnDelete();
            $table->foreignUlid('vocabulary_id')->nullable()->constrained('enrichment_vocabularies')->nullOnDelete();
            // The details asked for, in one lookup: one search serves all of a product's missing details.
            $table->json('attribute_keys');
            // The product, the details and the prompts' versions: the same question is not asked twice in 90 days.
            $table->string('fingerprint', 64);
            // The best of the answers: certain, likely, conflicting or not_found.
            $table->string('certainty', 12);
            // attribute => {value, unit, certainty, why, sources: [{url, host, quote, verified, independent, manufacturer}], decision}
            $table->json('results');
            $table->json('verifier')->nullable();
            $table->json('problems')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('run_id')->nullable()->constrained('runs')->nullOnDelete();
            $table->decimal('cost_usd', 10, 6)->default(0);
            $table->timestamps();

            $table->index(['shop_id', 'created_at']);
            $table->index(['shop_id', 'product_id', 'fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrichment_web_lookups');
    }
};
