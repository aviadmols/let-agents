<?php

namespace App\Modules\Enrichment\Scanning;

use App\Modules\Enrichment\Vocabulary\VocabularyDefinition;

/**
 * Whether a product could be tried on a person or placed in a room, decided in code (docs/try-on.md,
 * ADR 0012). Clothes, jewelry and whole pieces of furniture only: a shoe, a drill, a sofa leg or a
 * sofa cushion is "none".
 *
 * The vocabulary says it, per product type (`"tryon": "ring"`). A vocabulary that says nothing about
 * try-on at all leaves the product undecided (null), so "not looked at" is never mistaken for "no".
 * A vocabulary that says it for some types makes every other type "none"; `"tryon": false` on the
 * vocabulary says "no product of this branch" outright.
 *
 * A part or an accessory of a piece (a leg for a table, a cover for a sofa) is "none" even when its
 * type is furniture: the vocabulary's `tryon_exclude_patterns`, and the words below for furniture.
 */
final class TryOnEligibility
{
    public const NONE = 'none';

    /** The product types the vocabulary may name, and the group each belongs to. */
    public const CLASSES = [
        'top' => 'apparel',
        'bottom' => 'apparel',
        'dress' => 'apparel',
        'outerwear' => 'apparel',
        'ring' => 'jewelry',
        'bracelet' => 'jewelry',
        'necklace' => 'jewelry',
        'earrings' => 'jewelry',
        'watch' => 'jewelry',
        'furniture' => 'furniture',
    ];

    /** A piece's part or accessory, in the words stores use: "רגליים לשולחן", "cover for sofa". */
    private const PART_OF_FURNITURE = '~(?:^|\s)(?:רגל|רגלי|רגליים|ידית|ידיות|מכסה|כיסוי|כרית|כריות|גלגל|גלגלים|מדף\s+חלופי|חלקי\s+חילוף|ערכת\s+תיקון)\s+(?:ל|עבור\s)|(?:legs?|cover|covers|cushions?|handles?|casters?|wheels?|spare\s+parts?)\s+for\s|חלק(?:י)?\s+חילוף~iu';

    /**
     * @return array{class: string, source: string}|null null when the vocabulary does not decide it;
     *                                                   source is "type" or "part"
     */
    public static function decide(?VocabularyDefinition $vocabulary, ?string $typeKey, string $title): ?array
    {
        if ($vocabulary === null || $typeKey === null || ! $vocabulary->declaresTryOn()) {
            return null;
        }

        $class = $vocabulary->tryOnClass($typeKey);

        if ($class === null) {
            return ['class' => self::NONE, 'source' => 'type'];
        }

        foreach ($vocabulary->tryOnExcludePatterns() as $pattern) {
            if (@preg_match('~'.$pattern.'~iu', $title) === 1) {
                return ['class' => self::NONE, 'source' => 'part'];
            }
        }

        if (self::CLASSES[$class] === 'furniture' && preg_match(self::PART_OF_FURNITURE, $title) === 1) {
            return ['class' => self::NONE, 'source' => 'part'];
        }

        return ['class' => $class, 'source' => 'type'];
    }

    public static function groupOf(string $class): ?string
    {
        return self::CLASSES[$class] ?? null;
    }
}
