<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The store's rules for its answers, in its own words ("never offer a coupon", "when asked about sizes, suggest the size
 * guide"): how to answer, never a fact. Each one checked before it is kept (CheckAnswerChange), and given to the writer
 * with every answer (answer_site v23). Who wrote it and when, so the team sees what changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_answer_rules', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('text', 500);
            $table->foreignId('created_by')->nullable();
            $table->timestamps();
            $table->index(['shop_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_answer_rules');
    }
};
