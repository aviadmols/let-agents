<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A shopper's wishlist in a shop: products by the store's own ids, newest first. A guest's list
 * belongs to their browser (a random token, kept here only as its hash); once they sign in to the
 * store it belongs to their store account, and the guest list joins it. The share code opens a
 * read-only copy, for a link or WhatsApp.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shortlist_wishlists', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            // sha256 of the browser's token; null once the list is an account's.
            $table->string('token_hash', 64)->nullable();
            // The store account: shopify:<customer id> or wordpress:<user id>.
            $table->string('customer_ref', 80)->nullable();
            $table->string('share_code', 16)->unique();
            $table->json('items');
            $table->timestamps();

            $table->unique(['shop_id', 'token_hash']);
            $table->unique(['shop_id', 'customer_ref']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shortlist_wishlists');
    }
};
