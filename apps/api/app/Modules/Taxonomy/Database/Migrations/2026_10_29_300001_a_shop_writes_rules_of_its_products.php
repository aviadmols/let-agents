<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A shop writes rules of its products in its own words (Aviad, 29.10: "the book 'X' does not belong with books like
 * 'Y'; it goes with the biographies"), on the knowledge screen.
 *
 * - rules: the rule as written, what was understood of it, and the plan of what it changes, until the team approves
 *   or rejects it; applied, it stays as the record of what was changed and why.
 * - pairs: two products the team kept apart (never alike) or put together (always alike), by a rule it approved;
 *   read by the alike by tags (Related) and the search's similar products.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taxonomy_rules', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('text', 600);
            $table->string('status', 12); // reading | proposed | unclear | applied | rejected | failed
            $table->string('note', 600)->nullable(); // what was understood, or the question asked back
            $table->json('plan')->nullable(); // the changes, a product each, as shown to the team
            $table->string('fingerprint', 64)->nullable();
            $table->string('proposed_by', 120)->nullable();
            $table->string('audited_by', 120)->nullable();
            $table->string('run_id', 26)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'status']);
        });

        Schema::create('taxonomy_pairs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignUlid('product_id')->constrained('catalog_products')->cascadeOnDelete();
            $table->foreignUlid('other_id')->constrained('catalog_products')->cascadeOnDelete();
            $table->string('kind', 8); // apart | alike
            $table->foreignUlid('rule_id')->nullable()->constrained('taxonomy_rules')->nullOnDelete();
            $table->timestamps();

            $table->unique(['product_id', 'other_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taxonomy_pairs');
        Schema::dropIfExists('taxonomy_rules');
    }
};
