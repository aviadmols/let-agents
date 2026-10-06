<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What a shop's search box searches: products, guides, pages and categories, one record
        // each, rebuilt every night. The browser downloads the same list for instant suggestions,
        // so its hash is its version.
        Schema::create('search_indexes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->unique()->constrained('shops')->cascadeOnDelete();
            $table->string('hash', 64);
            $table->longText('items');
            $table->json('counts');
            $table->timestamp('built_at');
            $table->timestamps();
        });

        // How often each query was searched on a day, how often it found nothing, and how often
        // someone clicked a result. The query is kept normalized: no case, no niqqud, no final
        // letters. Nothing about who searched.
        Schema::create('search_terms', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->date('day');
            $table->string('query', 120);
            $table->unsignedInteger('searches')->default(0);
            $table->unsignedInteger('empty')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('last_results')->default(0);
            $table->timestamps();

            $table->unique(['shop_id', 'day', 'query']);
            $table->index(['shop_id', 'day']);
        });

        // Which result was clicked for which query.
        Schema::create('search_clicks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->date('day');
            $table->string('query', 120);
            $table->string('item', 80);
            $table->string('title', 300)->default('');
            $table->unsignedInteger('clicks')->default(0);
            $table->timestamps();

            $table->unique(['shop_id', 'day', 'query', 'item']);
        });

        // "קרש" means עץ in this shop. Products that say the second are found by the first. The
        // team adds them, or approves them when the daily review proposes them.
        Schema::create('search_synonyms', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('term', 80);
            $table->string('means', 120);
            $table->string('origin', 20)->default('team');
            $table->timestamps();

            $table->unique(['shop_id', 'term', 'means']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_synonyms');
        Schema::dropIfExists('search_clicks');
        Schema::dropIfExists('search_terms');
        Schema::dropIfExists('search_indexes');
    }
};
