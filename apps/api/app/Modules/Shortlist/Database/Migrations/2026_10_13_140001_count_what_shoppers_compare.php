<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How often shoppers compare, per shop and day: every comparison opened (item ''), and each
 * product in it. For the shop's compare and wishlist screen; no shopper is kept here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shortlist_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->date('day');
            // compare
            $table->string('kind', 20);
            // '' for the comparison itself, else the store's product id
            $table->string('item', 64)->default('');
            $table->unsignedInteger('n')->default(0);
            $table->timestamps();

            $table->unique(['shop_id', 'day', 'kind', 'item']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shortlist_counts');
    }
};
