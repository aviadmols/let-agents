<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The sets of products a question asked to see, as found: by the words of what was asked, the
 * products they were chosen from (fingerprint), and the products chosen. Asked again with the same
 * products to choose from, the set is read from here and no model is asked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('thinking_product_sets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('subject_key', 191);
            $table->string('subject', 300);
            $table->string('fingerprint', 64);
            $table->string('title', 200)->nullable();
            $table->json('product_ids');
            // none fit exactly: the nearest the store has, said to be that, and their name
            $table->json('near_ids')->nullable();
            $table->string('near_title', 200)->nullable();
            // too broad to choose well: one question to the shopper, with the options to narrow it by
            $table->json('ask')->nullable();
            $table->json('candidate_ids');
            $table->string('chosen_by', 160)->nullable();
            $table->string('run_id', 26)->nullable();
            $table->timestamps();

            $table->unique(['shop_id', 'subject_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('thinking_product_sets');
    }
};
