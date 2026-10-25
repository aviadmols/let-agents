<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A demo's preparation, a step at a time, for the scan screen to show and the sweep to pick up when a step stopped:
 * the site read (what it runs on, its name), the store's scan started, the home page's picture taken (or given up
 * on), how many times its preparation was tried, and how many times whoever asked tried again after a problem.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('demo_sites', function (Blueprint $table): void {
            $table->timestamp('read_at')->nullable();
            $table->timestamp('scan_at')->nullable();
            $table->timestamp('shot_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('retries')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('demo_sites', function (Blueprint $table): void {
            $table->dropColumn(['read_at', 'scan_at', 'shot_at', 'attempts', 'retries']);
        });
    }
};
