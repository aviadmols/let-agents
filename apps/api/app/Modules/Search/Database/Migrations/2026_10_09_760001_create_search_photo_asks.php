<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each search by photo, for the team to check: a reduced copy of the photo, what the reader saw,
 * the tags the shopper got and the first results. Kept search.photo_keep_days, except the rows
 * the team marked right or wrong, which are the set every change to photo search is measured
 * against. Nothing about who searched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_photo_asks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('photo_hash', 64);
            // The photo, reduced and as JPEG, base64: one copy to look at and re-read, one small one for the list.
            $table->text('photo')->nullable();
            $table->text('thumb')->nullable();
            $table->string('object', 120)->nullable();
            $table->decimal('sure', 3, 2)->nullable();
            $table->string('doubt', 30)->nullable();
            $table->boolean('second')->default(false);
            $table->string('main', 200)->nullable();
            $table->json('tags');
            $table->json('results');
            $table->unsignedInteger('total')->default(0);
            // The team's word: right, wrong (and what it was), or nothing yet.
            $table->string('verdict', 10)->nullable();
            $table->string('expected', 120)->nullable();
            $table->timestamp('marked_at')->nullable();
            // The last check of the marked rows against the reader as it is now.
            $table->string('checked_main', 200)->nullable();
            $table->boolean('checked_right')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'created_at']);
            $table->index(['shop_id', 'verdict']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_photo_asks');
    }
};
