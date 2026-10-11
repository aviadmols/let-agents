<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The product the team says a photo was of, and where the check found it among the results: with
 * the order as it is, and with the second ordering. From these, how often the right product is
 * first, in the first three and in the first five.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('search_photo_asks', function (Blueprint $table) {
            $table->string('expected_product', 64)->nullable();
            $table->unsignedSmallInteger('checked_rank')->nullable();
            $table->unsignedSmallInteger('checked_rank_ranked')->nullable();
            $table->timestamp('ranked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('search_photo_asks', function (Blueprint $table) {
            $table->dropColumn(['expected_product', 'checked_rank', 'checked_rank_ranked', 'ranked_at']);
        });
    }
};
