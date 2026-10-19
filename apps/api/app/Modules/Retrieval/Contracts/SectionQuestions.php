<?php

namespace App\Modules\Retrieval\Contracts;

/**
 * The questions a shopper might ask that one section of a page or post answers ("אפשר להחזיר
 * טבעת שהוגדלה?" for a returns section), written once per section text by another module. The
 * index embeds them as a second vector of the section, so a question finds the section that
 * answers it even when the two share no words. Never shown to a shopper; never a source an answer
 * may cite (the section's own text is).
 *
 * The index asks every class tagged `retrieval.section_questions`, by the sha256 of each section's
 * text (RetrievalChunk::text_hash): what was written for a text stays with that text.
 */
interface SectionQuestions
{
    public const TAG = 'retrieval.section_questions';

    /**
     * Runs inside the shop's tenant context.
     *
     * @param  list<string>  $textHashes
     * @return array<string, list<string>> questions by text hash, in a stable order; a hash with none is left out
     */
    public function questions(string $shopId, array $textHashes): array;
}
