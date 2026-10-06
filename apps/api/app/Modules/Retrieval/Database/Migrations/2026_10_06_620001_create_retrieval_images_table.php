<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A vector of each product's main picture, so code can find products that look alike and
        // search pictures by words. One row per product. The address is the version: a store that
        // uploads a new picture gives it a new address, and only then is it embedded again.
        Schema::create('retrieval_images', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignUlid('product_id')->unique()->constrained('catalog_products')->cascadeOnDelete();
            $table->string('external_id', 64);
            $table->string('title', 500);
            $table->text('image_url');
            $table->string('url_hash', 64);
            $table->string('embedding_model', 120)->nullable();
            $table->unsignedSmallInteger('dimensions')->nullable();
            $table->string('error', 40)->nullable();
            $table->timestamp('embedded_at')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'embedding_model']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE retrieval_images ADD COLUMN embedding vector');
        } else {
            Schema::table('retrieval_images', fn (Blueprint $table) => $table->text('embedding')->nullable());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('retrieval_images');
    }
};
