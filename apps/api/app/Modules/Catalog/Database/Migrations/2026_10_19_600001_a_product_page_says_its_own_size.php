<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The size a product's own page says it is ("מידה נוכחית: 54", a ring's data-current-size), when the
 * store keeps it outside its variants and its feed: one ring, one size. Search reads it as the
 * product's size, for "מידה 54" and "במידה גדולה".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_product_choices', function (Blueprint $table): void {
            $table->decimal('size', 8, 2)->nullable()->after('fields');
            $table->string('size_label', 60)->nullable()->after('size');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_product_choices', function (Blueprint $table): void {
            $table->dropColumn(['size', 'size_label']);
        });
    }
};
