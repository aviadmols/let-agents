<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A word shoppers typed that the store does not write, and the store's word it means ("מפוספס": "פסים"), or none: read
 * once by a small model against the store's own words (WordMeanings), kept with the version of those words it was read
 * against, so a "none" is read again once the store's words change (new products).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_word_meanings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('word', 80);
            $table->string('term', 80)->nullable();
            $table->string('words_hash', 20);
            $table->string('model', 120)->nullable();
            $table->timestamps();
            $table->unique(['shop_id', 'word']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_word_meanings');
    }
};
