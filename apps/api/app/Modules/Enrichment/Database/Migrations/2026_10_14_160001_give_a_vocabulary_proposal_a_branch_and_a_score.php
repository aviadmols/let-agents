<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A proposal can be for one branch of the catalog (a category and what is below it) instead of the
 * whole store, and it gets a score: what code measured on a pilot and what the reviewing model
 * judged, with the reasons it could not pass whatever the score. docs/strict-scan.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrichment_schema_proposals', function (Blueprint $table) {
            // {key, name, path, root, products, split_from}: the branch plan's entry. Null: the whole catalog.
            $table->json('branch')->nullable();
            $table->string('branch_key', 41)->nullable();
            // 0 to 100, and the parts it is made of.
            $table->unsignedTinyInteger('score')->nullable();
            $table->json('score_parts')->nullable();
            $table->json('hard_failures')->nullable();

            $table->index(['shop_id', 'branch_key', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('enrichment_schema_proposals', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'branch_key', 'created_at']);
            $table->dropColumn(['branch', 'branch_key', 'score', 'score_parts', 'hard_failures']);
        });
    }
};
