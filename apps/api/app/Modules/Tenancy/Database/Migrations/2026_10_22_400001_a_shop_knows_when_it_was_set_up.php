<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When the shop's manager went through the first-run screens (connection, what Lets found, the scan, the
 * first steps). Until then a new shop's panel opens on them; after, never again. Shops that existed
 * before these screens were set up long ago: they are marked so now, and keep opening on their home.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table): void {
            $table->timestamp('onboarded_at')->nullable();
        });

        DB::table('shops')->whereNull('onboarded_at')->update(['onboarded_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table): void {
            $table->dropColumn('onboarded_at');
        });
    }
};
