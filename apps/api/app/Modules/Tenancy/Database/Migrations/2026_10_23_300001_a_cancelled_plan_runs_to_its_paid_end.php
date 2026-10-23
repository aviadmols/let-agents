<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A plan the owner cancelled keeps the shop working until the end of the period already paid (or of its free
 * trial): billing stops at once, the search stays on the site until plan_ends_at, and the shop pauses then
 * (EndDuePlans). Null: nothing runs on, a cancelled shop is paused at once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table): void {
            $table->timestamp('plan_ends_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table): void {
            $table->dropIndex(['plan_ends_at']);
            $table->dropColumn('plan_ends_at');
        });
    }
};
