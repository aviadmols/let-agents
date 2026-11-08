<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a post lives, as the team chose it: on the store's own site (sent to its blog as a draft) or on Lets (a page
 * at its own address, among the store's content). Null: a post published before the choice, which went to both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('improvement_posts', function (Blueprint $table) {
            $table->string('destination', 10)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('improvement_posts', function (Blueprint $table) {
            $table->dropColumn('destination');
        });
    }
};
