<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each shop's vocabulary, discovered from its own products: what a sample of them holds, counted
 * in code; the vocabulary a model proposes from those counts, measured in code and reviewed by a
 * model of the other family; and the operator's decision. An approved proposal becomes a version
 * of the shop's vocabulary, as an imported one does.
 *
 * Facts also say how sure their source is, and where they were read: a store field, the title,
 * the description, a page, a picture.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrichment_schema_proposals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            // discovered (counted), proposed (written and reviewed), approved, rejected, failed
            $table->string('status', 12);
            $table->unsignedInteger('sampled')->default(0);
            // The ready vocabulary the proposal starts from, when one fits.
            $table->string('template', 60)->nullable();
            $table->json('stats');
            $table->json('proposed')->nullable();
            // What code measured of the proposal on the sample: each type's and attribute's share.
            $table->json('coverage')->nullable();
            $table->json('review')->nullable();
            $table->json('problems')->nullable();
            $table->string('summary', 500)->nullable();
            // The counts and the prompts' versions: the same input is never proposed twice.
            $table->string('fingerprint', 64);
            $table->foreignUlid('run_id')->nullable()->constrained('runs')->nullOnDelete();
            $table->foreignUlid('vocabulary_id')->nullable()->constrained('enrichment_vocabularies')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'status', 'created_at']);
        });

        Schema::table('enrichment_facts', function (Blueprint $table) {
            // 0 to 1: how sure the source is. A store field is surer than a title, a title than a picture.
            $table->decimal('confidence', 4, 3)->nullable();
            // {source: field|title|description|page|image, ref: the field's name, the quote, the picture}
            $table->json('evidence')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('enrichment_facts', function (Blueprint $table) {
            $table->dropColumn(['confidence', 'evidence']);
        });
        Schema::dropIfExists('enrichment_schema_proposals');
    }
};
