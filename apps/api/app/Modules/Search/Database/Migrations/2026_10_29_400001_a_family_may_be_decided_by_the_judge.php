<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A word family approved, fixed or set aside by the judge (SettleWordFamilies), not by the team: the screen says so, and
 * the team's own word on it, later, makes it the team's (Aviad, 29.10: 69 families waited for an approval the team had
 * no reason to give).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('search_family_choices', function (Blueprint $table): void {
            $table->boolean('auto')->default(false)->after('words');
        });
    }

    public function down(): void
    {
        Schema::table('search_family_choices', function (Blueprint $table): void {
            $table->dropColumn('auto');
        });
    }
};
