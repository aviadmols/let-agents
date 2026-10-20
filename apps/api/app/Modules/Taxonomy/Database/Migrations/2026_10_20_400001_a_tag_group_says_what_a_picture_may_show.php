<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a group's tags may be read from a product's picture: a colour, a shape, a style or a motif
 * it shows may; what a stone is, a metal's karat or what a thing is made of may not, and stands only
 * on the store's own words. Off for the groups there are: a bank built before it says nothing of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('taxonomy_groups', function (Blueprint $table): void {
            $table->boolean('from_picture')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('taxonomy_groups', function (Blueprint $table): void {
            $table->dropColumn('from_picture');
        });
    }
};
