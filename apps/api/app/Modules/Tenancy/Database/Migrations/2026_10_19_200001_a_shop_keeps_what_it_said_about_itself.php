<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the shop said about itself, on its settings page: whether it is a store, a content site or
 * both; its languages beyond the main one (content_locale); and what it sells, in its own words.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table): void {
            $table->json('profile')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table): void {
            $table->dropColumn('profile');
        });
    }
};
