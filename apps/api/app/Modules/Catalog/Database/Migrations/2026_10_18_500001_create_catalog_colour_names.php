<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A shop's own colour names that code's words do not know ("Ocean Mist", "חול מדברי"), each placed
 * once among the shades and patterns (PlaceColourNames: one family writes, the other checks) and then
 * read by VariantRows like any word it knows. A name the two did not agree on, or could not place,
 * is kept too, so it is never asked again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_colour_names', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('name_key', 120);
            $table->string('name', 120);
            $table->json('shades')->nullable();
            $table->string('pattern', 20)->nullable();
            // placed: both agreed; unknown: not a colour the list holds; disputed: the checker disagreed.
            $table->string('status', 20);
            $table->string('source', 20)->default('model');
            $table->string('models', 160)->nullable();
            $table->foreignUlid('run_id')->nullable();
            $table->timestamps();

            $table->unique(['shop_id', 'name_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_colour_names');
    }
};
