<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The choices a product page asks for that the store's feed does not carry (a length, a size), read
 * from the page itself once a day (ReadPageChoices). One row a product: an empty list means the page
 * was read and asks for nothing. The form's hash tells a page that did not change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_product_choices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignUlid('product_id')->constrained('catalog_products')->cascadeOnDelete();
            $table->json('fields');
            $table->string('form_hash', 64)->nullable();
            $table->string('product_hash', 64)->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('changed_at')->nullable();
            $table->unsignedSmallInteger('failures')->default(0);
            $table->timestamps();

            $table->unique('product_id');
            $table->index(['shop_id', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_product_choices');
    }
};
