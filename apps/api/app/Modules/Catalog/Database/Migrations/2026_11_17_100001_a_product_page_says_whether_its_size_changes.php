<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a product's page says of changing its size, in its own words, beside the size it is: "לא ניתן לשנות את המידה"
 * under a ring of 59 (Rocks, 17.11: the assistant told it could be enlarged, from a guide on rings in general).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_product_choices', function (Blueprint $table): void {
            $table->string('size_note', 200)->nullable()->after('sizes');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_product_choices', function (Blueprint $table): void {
            $table->dropColumn('size_note');
        });
    }
};
