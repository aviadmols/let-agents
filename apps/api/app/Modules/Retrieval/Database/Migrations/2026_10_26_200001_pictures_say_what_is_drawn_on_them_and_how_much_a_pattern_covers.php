<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two more things a picture's description says (caption v8): what is only drawn, printed or written on the product,
 * apart from its words, so a T-shirt with a drawing of a shoe is no shoe in the search (Vans, "נעלי משבצות", 26.10);
 * and which print covers it and how much of it ("משבצות", most), so "נעלי משבצות" shows the shoes covered in checks
 * before the ones with checks on a logo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('retrieval_images', function (Blueprint $table) {
            // ["איור נעל ספורט", "כיתוב Off The Wall"]; null until described under v8
            $table->json('caption_depicted')->nullable();
            // {"name": "משבצות", "covers": "most"}, or {"covers": "none"} for a plain product; null until described under v8
            $table->json('caption_pattern')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('retrieval_images', function (Blueprint $table) {
            $table->dropColumn(['caption_depicted', 'caption_pattern']);
        });
    }
};
