<?php

namespace App\Modules\Search\Support;

/**
 * How sure the index is that a product holds a filter's value, by where the value was read from. Only that: whether a
 * product fits a search and where it stands among the results are decided apart (docs/design/2026-11-17-index-evidence-weights.md).
 *
 * A value a check found wrong for the product is never held by its words or its picture ("חישוקים" read from a picture
 * of studded earrings and rejected: Rocks QA, 17.11); one the store itself says, in a field or in the title, still is.
 */
final class Evidence
{
    /** The store's own field, variant or attribute. */
    public const FIELD = 95;

    /** A model read it from the store's text and a check agreed. */
    public const MODEL = 90;

    /** The product's title says it; also what a code the store files it under counts as (a category, a tag). */
    public const TITLE = 85;

    /** The store's description says it. */
    public const DESCRIPTION = 60;

    /** Its picture shows what kind of thing it is. */
    public const PICTURE_KIND = 55;

    /** Its picture shows it: a colour, a shape, a detail. */
    public const PICTURE = 35;

    /** Nothing filed: only its words (title aside) say it, its picture's words among them. */
    public const WORDS = 30;

    /** From here up a value is as good as stated: the index keeps a score only for what is under it. */
    public const STRONG = 80;

    /**
     * The score of an approved fact, by how it was read (its status reason: code_field, code_title, code_description,
     * code_image) and by whom (a model's reading of the text, checked).
     */
    public static function ofFact(string $reason, string $origin, string $key): int
    {
        return match (true) {
            str_contains($reason, 'image') => $key === 'type' ? self::PICTURE_KIND : self::PICTURE,
            str_contains($reason, 'description') => self::DESCRIPTION,
            str_contains($reason, 'title') => self::TITLE,
            str_contains($reason, 'field') => self::FIELD,
            str_contains($origin, 'model') || $origin === 'person' => self::MODEL,
            default => self::TITLE,
        };
    }

    /**
     * How sure a record holds a filter's code: its kept score where the evidence is weak, as good as stated where the
     * code is filed with none kept, its words alone otherwise; 0 where a check found the value wrong for it.
     *
     * @param  array<string, mixed>  $record  an index record
     */
    public static function of(array $record, int $code, bool $titleSays = false): int
    {
        if ($titleSays) {
            return self::TITLE;
        }
        if (in_array($code, array_map('intval', (array) ($record['no'] ?? [])), true)) {
            return 0;
        }
        $kept = $record['ev'][(string) $code] ?? $record['ev'][$code] ?? null;
        if ($kept !== null) {
            return (int) $kept;
        }

        return in_array($code, array_map('intval', (array) ($record['f'] ?? [])), true) ? self::TITLE : self::WORDS;
    }
}
