<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A search a shopper handed to their own AI: what was searched, the products found and what the
 * store knows of them, kept as one snapshot behind a short link for a few days. Nothing about the
 * shopper: the words, the products, the store's own facts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_shares', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('token', 24)->unique();
            // The same words and products within the hour reuse one link.
            $table->string('fingerprint', 64)->index();
            $table->string('query', 200);
            $table->json('snapshot');
            $table->unsignedInteger('opens')->default(0);
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_shares');
    }
};
