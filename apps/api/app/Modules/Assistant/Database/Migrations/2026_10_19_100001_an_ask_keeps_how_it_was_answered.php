<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every question asked keeps how it was answered, for the operator's answer quality screen: the run
 * that answered it (its calls and their ledger rows), the prompt version, the writer and the checker,
 * the checker's verdict, what was sent (counts, never the text), what it cost and how long it took;
 * and why it failed, as code reads it and as the team decides.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_search_asks', function (Blueprint $table): void {
            $table->ulid('run_id')->nullable()->after('answer_id');
            $table->unsignedSmallInteger('prompt_version')->nullable()->after('run_id');
            $table->string('writer_model', 80)->nullable()->after('prompt_version');
            $table->string('checker_model', 80)->nullable()->after('writer_model');
            $table->string('verdict', 40)->nullable()->after('checker_model');
            $table->json('trace')->nullable()->after('verdict');
            $table->decimal('cost_usd', 12, 6)->nullable()->after('trace');
            $table->unsignedInteger('duration_ms')->nullable()->after('cost_usd');
            $table->string('failure', 40)->nullable()->after('duration_ms');
            $table->string('failure_team', 40)->nullable()->after('failure');
            $table->index(['shop_id', 'failure']);
        });
    }

    public function down(): void
    {
        Schema::table('assistant_search_asks', function (Blueprint $table): void {
            $table->dropIndex(['shop_id', 'failure']);
            $table->dropColumn(['run_id', 'prompt_version', 'writer_model', 'checker_model', 'verdict', 'trace', 'cost_usd', 'duration_ms', 'failure', 'failure_team']);
        });
    }
};
