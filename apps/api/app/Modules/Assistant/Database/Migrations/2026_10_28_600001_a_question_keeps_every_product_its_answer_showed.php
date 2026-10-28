<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every product an answer showed, beside its picks: the products its words named and a set it listed ("show me all
 * the rings"), so the team sees in the shoppers' questions what the shopper was shown (28.10). External ids.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_search_asks', function (Blueprint $table): void {
            $table->json('shown')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('assistant_search_asks', function (Blueprint $table): void {
            $table->dropColumn('shown');
        });
    }
};
