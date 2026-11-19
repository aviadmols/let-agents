<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a store focuses what the assistant offers to go with a product (GoesWithSettings, AssistantGoesWithRules): which of
 * its kinds may be paired with which, and what is never offered (a kind, a category, a vendor). One row a shop.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_goes_with_rules', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->unique()->constrained('shops')->cascadeOnDelete();
            // [{a, b, allow}] by the vocabulary's type keys, read both ways
            $table->json('pairs');
            // [{scope: type|category|vendor, value}]
            $table->json('never');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_goes_with_rules');
    }
};
