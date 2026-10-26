<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What shoppers do with a search, a day at a time, for the store to learn from (LearnFromSearches): a product opened or
 * added to the cart, a chip taken off or put on, a looser search chosen, the AI asked after it. How many times, and by
 * how many shoppers (each counted once a day, by a hash kept only for that day). And what each store learned from it,
 * rewritten every night: products first for a search, words no chip for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_signals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->date('day');
            $table->string('kind', 20);
            $table->string('query', 200);
            $table->string('value', 200)->default('');
            $table->unsignedInteger('count')->default(0);
            $table->unsignedInteger('visitors')->default(0);
            $table->timestamps();
            $table->unique(['shop_id', 'day', 'kind', 'query', 'value']);
            $table->index(['shop_id', 'kind', 'day']);
        });

        Schema::create('search_learned', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('query', 200)->default('');
            $table->string('value', 200);
            $table->float('weight')->default(0);
            $table->unsignedInteger('visitors')->default(0);
            $table->timestamps();
            $table->index(['shop_id', 'kind', 'query']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_learned');
        Schema::dropIfExists('search_signals');
    }
};
