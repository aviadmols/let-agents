<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Helpful?" under an assistant's answer in the search drawer: one thumb per question asked, the last
 * one the shopper pressed. Keyed by the question's id (assistant_search_asks), nothing about the shopper.
 * An answer rated down reaches the shop's notices.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_answer_ratings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('ask_id', 26);
            $table->boolean('helpful');
            $table->timestamps();
            $table->unique(['shop_id', 'ask_id']);
            $table->index(['shop_id', 'helpful', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_answer_ratings');
    }
};
