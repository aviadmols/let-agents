<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The questions a shopper might ask that one section of a page or post answers, written by a model
 * once per section text (SectionQuestionsTask) and read by the index as a second vector of the
 * section (Retrieval's SectionQuestions). Keyed by the sha256 of the section's text, not by the
 * section: a section rebuilt with the same text keeps its questions, and a changed text gets new ones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrichment_section_questions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            // RetrievalChunk::text_hash of the section the questions were written from.
            $table->string('text_hash', 64);
            // The prompt they were written with: a new version asks again; until then these stay.
            $table->unsignedSmallInteger('prompt_version');
            // Three to six questions, checked in code and sorted, so the same answer gives the same vector.
            $table->json('questions');
            $table->string('model', 120);
            $table->timestamps();

            $table->unique(['shop_id', 'text_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrichment_section_questions');
    }
};
