<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A vector for each of a product's other pictures (its gallery), beside the main picture's: a
 * shopper who photographs a ring from the side finds it by the store's side view. A photo search
 * keeps each product's closest picture. The address is the version, as for the main picture.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retrieval_gallery_images', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignUlid('product_id')->constrained('catalog_products')->cascadeOnDelete();
            $table->unsignedTinyInteger('position');
            $table->string('external_id', 64);
            $table->string('title', 500);
            $table->text('image_url');
            $table->string('url_hash', 64);
            $table->string('embedding_model', 120)->nullable();
            $table->unsignedSmallInteger('dimensions')->nullable();
            $table->string('error', 40)->nullable();
            $table->timestamp('embedded_at')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'position']);
            $table->index(['shop_id', 'embedding_model']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE retrieval_gallery_images ADD COLUMN embedding vector');
        } else {
            Schema::table('retrieval_gallery_images', fn (Blueprint $table) => $table->text('embedding')->nullable());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('retrieval_gallery_images');
    }
};
