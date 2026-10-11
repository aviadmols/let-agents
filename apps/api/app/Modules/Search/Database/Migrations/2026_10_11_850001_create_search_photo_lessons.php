<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a shop's team taught photo search: a photo they marked wrong, read again by the stronger
 * model with their word, and what it turned out to show. The same photo is answered from the
 * lesson from then on, and the shop's latest lessons go to the reader as notes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_photo_lessons', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('photo_hash', 64);
            // What the reader saw, what the team said, and what the photo shows after the second look.
            $table->string('seen', 120)->nullable();
            $table->string('team', 120)->nullable();
            $table->string('object', 120);
            $table->string('main_id', 80)->nullable();
            $table->string('main_title', 200)->nullable();
            $table->timestamps();

            $table->unique(['shop_id', 'photo_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_photo_lessons');
    }
};
