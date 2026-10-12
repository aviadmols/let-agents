<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An order's part that came through the store's search: the products in it the same visitor added
 * to the cart from the search results or the assistant's picks, within the attribution window. Kept
 * apart from the widget's attributed part, so neither is counted in the other.
 *
 * And who reported the order: the WooCommerce plugin, or the Shopify pixel (which runs in the
 * shopper's browser, so its orders are reported, not verified).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analytics_orders', function (Blueprint $table) {
            $table->decimal('search_total', 12, 2)->default(0)->after('attributed_items');
            $table->json('search_items')->nullable()->after('search_total');
            $table->string('reported_by', 10)->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('analytics_orders', function (Blueprint $table) {
            $table->dropColumn(['search_total', 'search_items', 'reported_by']);
        });
    }
};
