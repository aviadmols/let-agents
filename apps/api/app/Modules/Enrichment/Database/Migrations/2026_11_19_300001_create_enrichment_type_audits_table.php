<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The type audit (AuditProductTypes): a product whose type code read from the store's filing alone, and whose own words
 * do not say it, is put to a model of the other family. Its verdict is kept here, by the fingerprint of what was asked,
 * so the same question is never asked twice and the operator sees what was decided and why.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrichment_type_audits', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignUlid('product_id')->constrained('catalog_products')->cascadeOnDelete();
            // title, caption, the store's names for it, the bank's types, the type code gave, the prompt version
            $table->string('fingerprint', 64);
            // why code doubted it: caption_silent, no_caption, two_types, joined_name
            $table->json('reasons')->nullable();
            $table->string('code_type', 60)->nullable();
            // the model's verdict: a type key of the bank, "none", or null while it is asked
            $table->string('type', 60)->nullable();
            $table->unsignedSmallInteger('confidence')->nullable();
            $table->text('why')->nullable();
            $table->string('model', 120)->nullable();
            // asked, applied (the type replaced), removed (the type taken off), kept (code was right), held (under the
            // threshold: nothing changed), rejected (the reply broke its schema or named no type of the bank)
            $table->string('status', 20)->index();
            $table->json('problems')->nullable();
            $table->foreignUlid('batch_id')->nullable()->constrained('enrichment_batches')->nullOnDelete();
            $table->ulid('run_id')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'fingerprint']);
            $table->index(['shop_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrichment_type_audits');
    }
};
