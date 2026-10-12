<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many times a search led to an add to the cart: per query and day, and per product. The
 * purchases that followed are on the orders (analytics_orders.search_items), by the same visitor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('search_terms', function (Blueprint $table) {
            $table->unsignedInteger('added')->default(0)->after('clicks');
        });

        Schema::table('search_clicks', function (Blueprint $table) {
            $table->unsignedInteger('added')->default(0)->after('clicks');
        });
    }

    public function down(): void
    {
        Schema::table('search_terms', fn (Blueprint $table) => $table->dropColumn('added'));
        Schema::table('search_clicks', fn (Blueprint $table) => $table->dropColumn('added'));
    }
};
