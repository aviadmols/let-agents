<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The last visit that changed a wishlist: address, device, browser, system, session, page, and
 * where the shopper came from. The address and the session are cleared after
 * shortlist.visit_keep_days.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shortlist_wishlists', function (Blueprint $table) {
            $table->string('ip', 45)->nullable();
            $table->string('device', 10)->nullable();
            $table->string('browser', 30)->nullable();
            $table->string('os', 20)->nullable();
            $table->string('session_id', 64)->nullable();
            $table->string('page', 300)->nullable();
            $table->string('referrer', 120)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shortlist_wishlists', function (Blueprint $table) {
            $table->dropColumn(['ip', 'device', 'browser', 'os', 'session_id', 'page', 'referrer']);
        });
    }
};
