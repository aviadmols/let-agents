<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The shop's owner may end the plan (the shop pauses, nothing is lost) or ask for the shop and all it
 * holds to be deleted: it pauses at once and is deleted on deletion_due_at, unless the owner keeps it
 * before then (Settings · Privacy and account).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table): void {
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('deletion_due_at')->nullable()->index();
            $table->string('deletion_asked_by', 190)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table): void {
            $table->dropIndex(['deletion_due_at']);
            $table->dropColumn(['cancelled_at', 'deletion_due_at', 'deletion_asked_by']);
        });
    }
};
