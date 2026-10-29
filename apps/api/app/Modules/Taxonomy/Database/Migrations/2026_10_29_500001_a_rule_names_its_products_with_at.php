<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a rule names with "@" (Aviad, 29.10): a product, a category, a post or a page chosen from the store's search
 * as the rule is written, kept by its id, so the rule is read about exactly what the team meant ("tree of life" was
 * not found by its words).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('taxonomy_rules', function (Blueprint $table): void {
            $table->json('mentions')->nullable()->after('text');
        });
    }

    public function down(): void
    {
        Schema::table('taxonomy_rules', function (Blueprint $table): void {
            $table->dropColumn('mentions');
        });
    }
};
