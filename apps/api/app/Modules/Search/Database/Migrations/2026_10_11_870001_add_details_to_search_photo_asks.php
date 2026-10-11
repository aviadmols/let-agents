<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** What told the photographed object apart ("אבן אובלית בצבע תכלת"), for the photo log. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('search_photo_asks', function (Blueprint $table) {
            $table->json('details')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('search_photo_asks', function (Blueprint $table) {
            $table->dropColumn('details');
        });
    }
};
