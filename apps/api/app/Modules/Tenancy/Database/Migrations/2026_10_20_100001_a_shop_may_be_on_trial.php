<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A shop on trial: until when, and what its own settings were before the trial's limits replaced
 * them (StartTrial), so ending the trial puts them back as they were.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table): void {
            $table->timestamp('trial_ends_at')->nullable();
            $table->json('trial_saved')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table): void {
            $table->dropColumn(['trial_ends_at', 'trial_saved']);
        });
    }
};
