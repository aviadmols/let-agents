<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The other sizes a product's page offers it in, beside the one it is: a ring of 53 the store enlarges
 * to 54 or makes smaller to 51 ("הגדלה ל 54", a picker of sizes around the current one). Search reads
 * every one of them as a size of the product, so "מידה 54" finds the ring of 53 that is made 54.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_product_choices', function (Blueprint $table): void {
            $table->json('sizes')->nullable()->after('size_label');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_product_choices', function (Blueprint $table): void {
            $table->dropColumn('sizes');
        });
    }
};
