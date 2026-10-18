<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each product's variants as rows (VariantRows), so "black, size M, in stock" is asked of one row
 * and not of a product that has black somewhere and M somewhere. Built again from the product's
 * feed record whenever it changes; the record stays the source.
 *
 * The colour is kept three ways: the store's own name (for display), the shades it reads as (for a
 * precise search) and their families (for the filter). Several, as JSON lists: a variant can be
 * black and white, and a shade sits in two families.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_variants', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignUlid('product_id')->constrained('catalog_products')->cascadeOnDelete();
            $table->string('external_id', 64);
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('title')->default('');
            $table->string('sku', 120)->nullable();
            $table->string('color_label', 120)->nullable();
            $table->json('color_shades')->nullable();
            $table->json('color_families')->nullable();
            $table->string('color_hex', 7)->nullable();
            $table->string('pattern', 20)->nullable();
            $table->string('size_label', 60)->nullable();
            $table->string('size_key', 20)->nullable();
            $table->unsignedSmallInteger('size_position')->nullable();
            $table->json('options')->nullable();
            $table->decimal('price', 12, 2)->nullable();
            $table->boolean('in_stock')->default(false);
            $table->integer('quantity')->nullable();
            $table->boolean('purchasable')->default(false);
            $table->text('image_url')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'external_id']);
            $table->index(['shop_id', 'in_stock']);
            $table->index(['shop_id', 'size_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_variants');
    }
};
