<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A case keeps what the writer was given when it was made (its snapshot), so a prompt is compared with
 * another on the same data, apart from the same question on today's catalogue. A baseline case is a
 * real question kept to measure every change by, with or without an expectation. Every run of the
 * cases is kept with its numbers and each case's result, so two runs can be compared.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_quality_cases', function (Blueprint $table): void {
            $table->string('kind', 20)->default('regression')->after('ask_id');
            $table->longText('snapshot')->nullable()->after('expect');
            $table->timestamp('snapshot_at')->nullable()->after('snapshot');
            $table->index(['shop_id', 'kind']);
        });

        Schema::create('assistant_quality_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->nullable()->constrained('shops')->cascadeOnDelete();
            $table->string('label', 120)->nullable();
            // frozen: on each case's snapshot; live: on today's catalogue and pages
            $table->string('mode', 10);
            $table->string('kind', 20)->nullable();
            $table->unsignedSmallInteger('prompt_version')->nullable();
            $table->json('totals')->nullable();
            $table->foreignId('created_by')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('assistant_quality_results', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('run_id')->constrained('assistant_quality_runs')->cascadeOnDelete();
            $table->foreignUlid('case_id')->constrained('assistant_quality_cases')->cascadeOnDelete();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('outcome', 40)->nullable();
            $table->string('verdict', 40)->nullable();
            $table->string('failure', 40)->nullable();
            $table->boolean('passed')->nullable();
            $table->json('problems')->nullable();
            $table->text('answer')->nullable();
            $table->json('picks')->nullable();
            $table->decimal('cost_usd', 12, 6)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();
            $table->unique(['run_id', 'case_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_quality_results');
        Schema::dropIfExists('assistant_quality_runs');
        Schema::table('assistant_quality_cases', function (Blueprint $table): void {
            $table->dropIndex(['shop_id', 'kind']);
            $table->dropColumn(['kind', 'snapshot', 'snapshot_at']);
        });
    }
};
