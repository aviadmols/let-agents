<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The benchmarks' reports, one per shop and run: the writer benchmark (FactSheet) and the
 * retrieval benchmark, with each question's result, so the operator sees them on the shop's page.
 * Their working files live in the container and go with every deployment; these stay.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_bench_reports', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            // factsheet or retrieval
            $table->string('kind', 20);
            // The run's name, its file's: one report per shop, kind and name, written again when run again.
            $table->string('label', 120);
            $table->unsignedInteger('questions')->default(0);
            $table->json('summary');
            $table->json('rows');
            $table->timestamps();

            $table->unique(['shop_id', 'kind', 'label']);
            $table->index(['shop_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_bench_reports');
    }
};
