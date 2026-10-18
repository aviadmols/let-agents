<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A question the team fixed, kept as a case that must stay fixed: asked again (a replay) before a new
 * prompt, model or retrieval change goes out, and checked in code against what the team expects of
 * it. Its last run is kept beside it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_quality_cases', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->ulid('ask_id')->nullable();
            $table->string('question', 500);
            $table->json('products')->nullable();
            // outcome, contains (any of), absent (none of), picks (any of external ids)
            $table->json('expect');
            $table->string('note', 300)->nullable();
            $table->foreignId('created_by')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->boolean('last_passed')->nullable();
            $table->json('last_problems')->nullable();
            $table->text('last_answer')->nullable();
            $table->string('last_outcome', 40)->nullable();
            $table->unsignedSmallInteger('last_prompt_version')->nullable();
            $table->decimal('last_cost_usd', 12, 6)->nullable();
            $table->timestamps();
            $table->index(['shop_id', 'last_passed']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_quality_cases');
    }
};
