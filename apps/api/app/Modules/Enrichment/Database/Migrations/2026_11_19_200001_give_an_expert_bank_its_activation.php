<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A bank that passed can be made the store's vocabulary by hand (ActivateExpertBank), and undone. What it became and
 * what it replaced are kept on it, so the undo restores exactly the vocabularies it retired.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrichment_expert_banks', function (Blueprint $table) {
            // {vocabulary_id, version, replaced: [{id, key, version}], activated_at, run_id, deactivated_at?, restored?}
            $table->json('activation')->nullable()->after('problems');
        });
    }

    public function down(): void
    {
        Schema::table('enrichment_expert_banks', function (Blueprint $table) {
            $table->dropColumn('activation');
        });
    }
};
