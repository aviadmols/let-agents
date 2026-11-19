<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a store's filter screen is arranged (ArrangeFilters): the screen code read from its search index, what one model
 * proposed (an order, value orders, filters hidden, values moved), how a model of another family scored each change,
 * and what code applied. The search index reads the newest row that did not fail (FilterLayouts) and applies only its
 * order and its hiding; every row is kept as the history of what was asked, scored and applied, with its cost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_filter_layouts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            // applied (code applied some change), kept (nothing passed: the screen stays as built), failed (the run stopped)
            $table->string('status', 12);
            // The screen, the prompts' versions, the two models and the limits: the same input is not asked twice.
            $table->string('fingerprint', 64);
            $table->string('writer', 160)->nullable();
            $table->string('checker', 160)->nullable();
            // The screen as the models read it: filters, values, counts and overlaps.
            $table->json('screen')->nullable();
            // The writer's reply as it came, and the changes code made of it (each with the id the checker scored).
            $table->json('proposal')->nullable();
            $table->json('items')->nullable();
            // Every item's score, verdict and reason as the checker gave them, and the ids it named that code had not.
            $table->json('review')->nullable();
            // What the index applies: the order, the value orders, the filters hidden with their reason, the values moved.
            $table->json('applied')->nullable();
            // What code ignored of the proposal (a key not on the screen, no measured overlap) and refused of what passed.
            $table->json('refused')->nullable();
            // Each model call: step, provider, model, tokens, cost; and one Hebrew line a step.
            $table->json('calls')->nullable();
            $table->json('steps')->nullable();
            $table->json('problems')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('cost_usd', 10, 4)->default(0);
            $table->foreignUlid('run_id')->nullable()->constrained('runs')->nullOnDelete();
            $table->timestamps();

            $table->index(['shop_id', 'created_at']);
            $table->index(['shop_id', 'fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_filter_layouts');
    }
};
