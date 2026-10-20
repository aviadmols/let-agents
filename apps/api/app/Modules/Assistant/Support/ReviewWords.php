<?php

namespace App\Modules\Assistant\Support;

/**
 * What the daily review says, as the shop manager reads it: what shoppers looked for and what the
 * store could add, never the assistant's own mistakes (it invented a book, named a product the store
 * does not have, refused, mixed two up). Those are the operator's, in each question's flag and note.
 * Reports written before the review was told so are read through this too.
 */
final class ReviewWords
{
    /** A sentence about the assistant getting it wrong: invented, made up, does not exist, refused, mixed up. */
    private const MISTAKE = '/המצי[אה]|ממציא|המצאה|בדה |בדוי|(?:הציע|המליץ|ציין|הזכיר|נתן|ענה|הפנה)[^.!?]{0,60}(?:לא קיי|שאינ(?:ו|ה|ם|ן) קיי|אינ(?:ו|ה|ם) קיי)|סירב|סירוב|התבלבל|בלבל|הזי(?:ה|ות)|invent|made[ -]up|hallucin|refus|confus|(?:suggest|recommend|nam)[a-z]*[^.!?]{0,60}(?:does ?n[o\x27]t exist|non-?existent)/iu';

    /** The text without its sentences about the assistant's mistakes; null when nothing is left. */
    public static function forMerchant(?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        $sentences = preg_split('/(?<=[.!?])\s+|\n+/u', trim($text)) ?: [];
        $kept = array_values(array_filter(array_map('trim', $sentences), fn (string $s): bool => $s !== '' && preg_match(self::MISTAKE, $s) !== 1));

        return $kept === [] ? null : implode(' ', $kept);
    }

    /**
     * The improvements the shop manager may act on: those about the store, not the assistant's mistakes.
     *
     * @param  list<string>  $lines
     * @return list<string>
     */
    public static function improvements(array $lines): array
    {
        return array_values(array_filter($lines, fn ($line): bool => is_string($line) && trim($line) !== '' && preg_match(self::MISTAKE, $line) !== 1));
    }
}
