<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One model's versions that a store sells as products of their own (a ring in amethyst and in citrine, a sandal in
 * black and in brown), as the store's own collection pages link them (ReadCollectionSiblings): each product's group,
 * and how the page names it ("Citrine"). Read again on every reading; a product in no group has no row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_siblings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignUlid('product_id')->constrained('catalog_products')->cascadeOnDelete();
            $table->string('group_key', 32);
            $table->string('label', 120)->nullable();
            $table->timestamps();

            $table->unique(['shop_id', 'product_id']);
            $table->index(['shop_id', 'group_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_siblings');
    }
};
