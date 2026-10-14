<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The store team's test may try a product the catalog has not read yet (added after the last
 * sync): such a try keeps the page's own product (its id, name and picture) instead of a catalog row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('try_on_jobs', function (Blueprint $table) {
            $table->ulid('product_id')->nullable()->change();
            $table->json('page_product')->nullable()->after('product_id');
        });
    }

    public function down(): void
    {
        Schema::table('try_on_jobs', function (Blueprint $table) {
            $table->dropColumn('page_product');
        });
    }
};
