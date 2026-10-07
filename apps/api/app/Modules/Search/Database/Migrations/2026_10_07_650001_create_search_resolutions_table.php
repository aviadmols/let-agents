<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What a search that found nothing should show from now on. Found at night: the query's
        // vector, candidates from the catalogue, a model that matches, a model of another family
        // that checks. Nothing here runs while a shopper waits.
        Schema::create('search_resolutions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('query', 120);
            $table->string('query_hash', 64);
            $table->string('status', 20);
            $table->json('products');
            $table->json('content');
            $table->string('synonym_means', 120)->nullable();
            $table->json('candidates');
            $table->string('fingerprint', 64);
            $table->unsignedInteger('searches')->default(0);
            $table->string('matched_by', 160)->nullable();
            $table->string('checked_by', 160)->nullable();
            $table->string('reason', 300)->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['shop_id', 'query_hash']);
            $table->index(['shop_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_resolutions');
    }
};
