<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A store's tag bank and what it says of each product and post.
 *
 * - groups: the kinds of tag the store's products are told apart by (genre, occasion, material),
 *   each inferred (a theme a reader may judge) or factual (what the product is made of, measures),
 *   and what it is used for: searched, shown as a filter, used for recommendations.
 * - tags: the bank's values, each with its words in the store's languages; a tag merged into
 *   another points at it and is no longer assigned.
 * - assignments: a tag on a product or a post, with the evidence for it, where it was read from
 *   (the catalogue's text, the picture, what is known of a well-known product) and how sure.
 * - proposals: a tag the bank lacks, proposed while products were tagged, until it is added,
 *   merged into one the bank has, or rejected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taxonomy_groups', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('key', 60);
            $table->string('label_he', 120);
            $table->string('label_en', 120);
            $table->string('kind', 12); // inferred | factual
            $table->boolean('multiple')->default(true);
            $table->boolean('searchable')->default(true);
            $table->boolean('filter')->default(false);
            $table->boolean('recommend')->default(true);
            $table->string('about', 300)->nullable();
            // the store's categories it applies to, in a store that sells more than one kind of thing
            // (genre for its books, size for its clothes); empty: all of them
            $table->json('scope')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->unsignedInteger('version');
            $table->timestamps();

            $table->unique(['shop_id', 'key']);
        });

        Schema::create('taxonomy_tags', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignUlid('group_id')->constrained('taxonomy_groups')->cascadeOnDelete();
            $table->string('key', 80);
            $table->string('label_he', 120);
            $table->string('label_en', 120);
            $table->json('words')->nullable(); // other words a shopper says for it, in either language
            $table->string('about', 300)->nullable();
            $table->string('status', 12)->default('active'); // active | merged | retired
            $table->ulid('merged_into')->nullable();
            // the broader tag of the same group it is part of ("כלכלה התנהגותית" of "כלכלה")
            $table->ulid('parent_id')->nullable();
            $table->unsignedInteger('version');
            $table->timestamps();

            $table->unique(['group_id', 'key']);
            $table->index(['shop_id', 'status']);
        });

        // a tag pointing at another tag: once the table and its key stand (Postgres refuses it before)
        Schema::table('taxonomy_tags', function (Blueprint $table): void {
            $table->foreign('merged_into')->references('id')->on('taxonomy_tags')->nullOnDelete();
            $table->foreign('parent_id')->references('id')->on('taxonomy_tags')->nullOnDelete();
        });

        Schema::create('taxonomy_assignments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('subject_type', 10); // product | content
            $table->ulid('subject_id');
            $table->foreignUlid('tag_id')->constrained('taxonomy_tags')->cascadeOnDelete();
            $table->string('source', 12); // catalog | picture | knowledge
            $table->string('evidence', 400)->nullable();
            $table->decimal('confidence', 4, 3);
            // how central it is to the product: 1 what it is mainly about, about .3 present in the background
            $table->decimal('weight', 4, 3)->default(0.5);
            // whether its text says the tag itself, or only what justifies it (a book on leadership that never says "leadership")
            $table->boolean('explicit')->default(false);
            $table->string('status', 12); // kept | review | rejected
            $table->string('checked', 12)->nullable(); // held | taken_out: what the other family said
            $table->string('check_note', 200)->nullable();
            $table->string('input_hash', 64);
            $table->unsignedInteger('bank_version');
            $table->string('run_id', 26)->nullable();
            $table->timestamps();

            $table->unique(['subject_type', 'subject_id', 'tag_id']);
            $table->index(['shop_id', 'subject_type', 'status']);
            $table->index('tag_id');
        });

        // each product and post as it was when it was last tagged, so only what changed is tagged again
        Schema::create('taxonomy_subjects', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('subject_type', 10);
            $table->ulid('subject_id');
            $table->string('input_hash', 64);
            $table->unsignedInteger('bank_version');
            $table->unsignedSmallInteger('dropped')->default(0); // tags the model gave that code did not keep: no such tag, no evidence
            $table->string('run_id', 26)->nullable();
            $table->timestamps();

            $table->unique(['subject_type', 'subject_id']);
            $table->index('shop_id');
        });

        Schema::create('taxonomy_proposals', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignUlid('group_id')->constrained('taxonomy_groups')->cascadeOnDelete();
            $table->string('label_he', 120);
            $table->string('label_en', 120);
            $table->string('norm', 120); // the label as it is compared, for its duplicates
            $table->json('subjects'); // the products and posts it was proposed for: [type, id]
            $table->string('why', 300)->nullable();
            $table->string('status', 12)->default('open'); // open | added | merged | rejected
            $table->foreignUlid('tag_id')->nullable()->constrained('taxonomy_tags')->nullOnDelete();
            $table->string('decision_note', 200)->nullable();
            $table->timestamps();

            $table->unique(['group_id', 'norm']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taxonomy_proposals');
        Schema::dropIfExists('taxonomy_subjects');
        Schema::dropIfExists('taxonomy_assignments');
        Schema::dropIfExists('taxonomy_tags');
        Schema::dropIfExists('taxonomy_groups');
    }
};
