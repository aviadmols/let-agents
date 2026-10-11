<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A post a model drafted for something shoppers look for and the store has no page about. The
 * team edits and publishes it; published, it is a guide of the store's own, served by the
 * platform and found by the search and the widget like any page of the site.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('improvement_posts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignUlid('proposal_id')->constrained('improvement_proposals')->cascadeOnDelete();
            $table->string('title', 200);
            $table->text('body');
            // The store's products the post is about, by their external ids.
            $table->json('products');
            $table->string('status', 20)->default('draft');
            // What the second model found wrong, if anything; empty when it passed.
            $table->boolean('checked')->default(false);
            $table->json('problems');
            $table->string('written_by', 160);
            $table->string('checked_by', 160)->nullable();
            $table->string('run_id', 26)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique('proposal_id');
            $table->index(['shop_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('improvement_posts');
    }
};
