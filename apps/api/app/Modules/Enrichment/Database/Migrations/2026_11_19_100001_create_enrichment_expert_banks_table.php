<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A store's attribute bank written for it alone, as an experiment (BuildExpertBank): no ready vocabulary is read, an
 * expert model writes the bank from the store's own words, code measures it on the store's products and a checker of
 * another family scores every item. A row here is a report. Nothing reads products with it, nothing approves it and
 * nothing advances it: it is its own table so no screen or gate of the vocabulary proposals ever picks it up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrichment_expert_banks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            // passed (the store's own numbers reach the thresholds), held (they do not), failed (the run stopped)
            $table->string('status', 12);
            // The facts, the prompts' versions, the two models and the thresholds: the same input is not asked twice.
            $table->string('fingerprint', 64);
            $table->unsignedInteger('products')->default(0);
            $table->string('writer', 160)->nullable();
            $table->string('checker', 160)->nullable();
            // What code counted of the store, as the expert received it.
            $table->json('facts')->nullable();
            // What is left after code and the checker: types and attributes with their values, words and patterns.
            $table->json('bank')->nullable();
            // The bank after each round, before the checker.
            $table->json('drafts')->nullable();
            // What code measured of the final bank on the store's products.
            $table->json('measurement')->nullable();
            // Every item's score, verdict and reason, as the checker gave them; and what it said that code ignored.
            $table->json('review')->nullable();
            // Everything taken out: the item, who took it out (code or checker), in which step and why.
            $table->json('removed')->nullable();
            // The bank's own numbers against the thresholds, and the hard failures.
            $table->json('gate')->nullable();
            // Each model call: step, provider, model, tokens, cost.
            $table->json('calls')->nullable();
            // One line a step, as the run tells it.
            $table->json('steps')->nullable();
            $table->json('problems')->nullable();
            $table->foreignUlid('run_id')->nullable()->constrained('runs')->nullOnDelete();
            $table->timestamps();

            $table->index(['shop_id', 'created_at']);
            $table->index(['shop_id', 'fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrichment_expert_banks');
    }
};
