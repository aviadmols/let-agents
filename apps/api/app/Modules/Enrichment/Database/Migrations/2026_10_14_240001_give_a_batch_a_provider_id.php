<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A batch handed to a provider's batch API keeps the provider and its id for the batch, so the
 * collector can ask for its state and read its answers later. Null for a batch answered one
 * question at a time or by a file somebody carried.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrichment_batches', function (Blueprint $table) {
            $table->string('provider', 40)->nullable()->after('runner');
            $table->string('provider_batch_id', 120)->nullable()->after('provider')->index();
            $table->timestamp('submitted_at')->nullable()->after('provider_batch_id');
        });
    }

    public function down(): void
    {
        Schema::table('enrichment_batches', function (Blueprint $table) {
            $table->dropIndex(['provider_batch_id']);
        });
        Schema::table('enrichment_batches', function (Blueprint $table) {
            $table->dropColumn(['provider', 'provider_batch_id', 'submitted_at']);
        });
    }
};
