<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One card per product, per page or post, and one for the store: everything known about it, in one
 * compact JSON that every model reads (the shopper's assistant, the search box, the readers, the
 * shopper's own AI). Built in code from the catalog and the approved facts, rewritten only when its
 * hash changes; a new product has its card minutes after the sync that brings it (BuildCards).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrichment_cards', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            // product, content or store
            $table->string('kind', 12);
            // The product's or the page's id; the store's card is "store".
            $table->string('subject_id', 36);
            $table->json('card');
            $table->string('hash', 64);
            $table->timestamp('built_at');
            $table->timestamps();

            $table->unique(['shop_id', 'kind', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrichment_cards');
    }
};
