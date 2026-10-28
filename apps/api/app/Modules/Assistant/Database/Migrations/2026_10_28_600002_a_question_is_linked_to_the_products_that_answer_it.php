<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the store's team says answers a question ("טבעת נישואין"): its categories, its tags, and products by hand. The
 * answer to it, and to a question worded like it, is chosen among them first (QuestionLinks). By external ids and tag
 * names, which outlive a scan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_question_links', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('question_key', 64);
            $table->string('question', 500);
            $table->json('categories')->nullable();
            $table->json('tags')->nullable();
            $table->json('products')->nullable();
            $table->foreignId('created_by')->nullable();
            $table->timestamps();
            $table->unique(['shop_id', 'question_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_question_links');
    }
};
