<?php

namespace App\Modules\Retrieval\Contracts;

/**
 * The topics a question is about (returns, shipping...), asked as a shopper asks and as a store
 * writes them: "מה מדיניות ההחזרים?" is also "מדיניות החזרות", "החזרת מוצר וקבלת זיכוי". What a
 * question is searched with again when its own words found no answer. Code only, no model.
 */
interface TopicQuestions
{
    /** @return list<string> */
    public function questions(string $question, string $locale): array;
}
