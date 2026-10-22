<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The review's words for the shop in English too, for a reader whose account is in English: {en: {summary, improvements}}. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_ask_reviews', function (Blueprint $table): void {
            $table->json('languages')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('assistant_ask_reviews', function (Blueprint $table): void {
            $table->dropColumn('languages');
        });
    }
};
