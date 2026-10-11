<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the photo log shows of each search: where the object was in the photo and whether it was cut
 * out before the comparison, the separate things the photo held and which one was searched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('search_photo_asks', function (Blueprint $table) {
            $table->json('box')->nullable();
            $table->boolean('cut')->default(false);
            $table->json('items')->nullable();
            $table->unsignedTinyInteger('item')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('search_photo_asks', function (Blueprint $table) {
            $table->dropColumn(['box', 'cut', 'items', 'item']);
        });
    }
};
