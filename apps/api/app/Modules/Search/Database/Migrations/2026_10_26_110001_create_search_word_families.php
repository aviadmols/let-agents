<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A store's word families: its own words a shopper means as one ("משובץ", "משובצות", "משבצות"; "שבי", "חטוף",
 * "חטופים"; "פנטזיה", "מדע בדיוני"), written by one model family and checked by the other (WriteWordFamilies), read
 * against the store's words of words_hash: written again only when they change. The index reads each family as the
 * store's synonyms.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_word_families', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('words_hash', 20);
            $table->json('families');
            $table->unsignedSmallInteger('prompt_version');
            $table->string('model', 120)->nullable();
            $table->timestamps();
            $table->unique('shop_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_word_families');
    }
};
