<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A store's goes-with map (BuildGoesWithMap): which of its own product types go with which, written once per store by one
 * model family from the list of its types, scored by the other, kept by code where both kinds hold products and the
 * score reaches the threshold. The assistant reads the latest map that passed to answer "what else do I need with
 * this?"; a row is otherwise the record of one build.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrichment_goes_with_maps', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            // passed (at least one pair kept), held (none kept), failed (the run stopped)
            $table->string('status', 12);
            // The types, the prompts' versions, the two models and the threshold: the same input is not asked twice.
            $table->string('fingerprint', 64);
            $table->string('writer', 160)->nullable();
            $table->string('checker', 160)->nullable();
            // The store's product types as the writer was given them: key, label, how many products, a few titles.
            $table->json('types')->nullable();
            // Every pair the writer proposed, as written.
            $table->json('proposed')->nullable();
            // The pairs kept: type, partner type, why, the checker's score.
            $table->json('pairs')->nullable();
            // Every pair's score, verdict and reason, as the checker gave them; and what it said that code ignored.
            $table->json('review')->nullable();
            // Everything taken out: the pair, who took it out (code or checker), and why.
            $table->json('removed')->nullable();
            // Each model call: step, provider, model, tokens, cost.
            $table->json('calls')->nullable();
            // One line a step, as the run tells it.
            $table->json('steps')->nullable();
            $table->json('problems')->nullable();
            $table->foreignUlid('run_id')->nullable()->constrained('runs')->nullOnDelete();
            $table->timestamps();

            $table->index(['shop_id', 'status', 'created_at']);
            $table->index(['shop_id', 'fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrichment_goes_with_maps');
    }
};
