<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How much of the store a demo read: how many products the store has in all (as its public feed says, when it says),
 * against the products the demo read; and how many of their pictures were looked at, for the team to see.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('demo_sites', function (Blueprint $table): void {
            $table->unsignedInteger('store_products')->nullable();
            $table->unsignedInteger('pictures')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('demo_sites', function (Blueprint $table): void {
            $table->dropColumn(['store_products', 'pictures']);
        });
    }
};
