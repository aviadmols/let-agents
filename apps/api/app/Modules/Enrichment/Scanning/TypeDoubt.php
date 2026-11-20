<?php

namespace App\Modules\Enrichment\Scanning;

use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Vocabulary\VocabularyDefinition;

/**
 * Which products' types code cannot vouch for, in code: the few a model is asked about (AuditProductTypes). Code gave
 * a product its type from the store's filing alone (a category, a tag, the store's type field) and the product's own
 * title says no word of that type; and either nothing else says it (its picture, as a model described it, says no word
 * of it, or there is no picture), or the filing itself is loose: the store's names for it name two types or more, or
 * one of them joins kinds ("Tees & Shirts" holds products of either). Or code read it from a Hebrew title where the type's
 * word is not what the name says the product is: after another noun ("מחמם בקבוקים", a bottle warmer), or joined to
 * "ל", for ("בסיס לסלקל", a car seat's base). Everything else is left alone.
 *
 * A mesh top filed under "T-shirts & button shirts" and tagged with both (Vans, 19.11) is such a product: code read
 * "T-shirt" from the filing, the title says mesh top, the picture says a sheer mesh top. Code alone cannot know mesh is
 * no T-shirt; it can know it does not know.
 */
final class TypeDoubt
{
    /** Where a type code read came from its own words: a type from there is doubted only where its word is not the name's head. */
    public const FROM_TITLE = 'title';

    public const CAPTION_SILENT = 'caption_silent';

    public const NO_CAPTION = 'no_caption';

    public const TWO_TYPES = 'two_types';

    public const JOINED_NAME = 'joined_name';

    /** A type read from the title, from a word that is not what the product is: another noun opens the name before it. */
    public const NOT_THE_HEAD = 'not_the_head';

    /** A type read from the title, from a word joined to "ל", "for": the product is made for one, not one itself. */
    public const MADE_FOR = 'made_for';

    /**
     * Words that count what a Hebrew name holds before saying what it is ("זוג מוצצים", "מארז בגדי גוף", "סט טבעות"): the
     * product is still the kind named after them. Grammar, never a store's words.
     */
    private const COUNTS = '/^(?:זוג|זוגות|צמד|מארז|מארזי|סט|סטים|ערכת|ערכה|ערכות|חבילת|חבילה|אריזת|אריזה|שלישיית|שלישייה|רביעיית|רביעייה|חמישיית|חמישייה|שישיית|שישייה|יח|יחידות|שני|שתי|שלושה|שלוש|ארבעה|ארבע|חמישה|חמש)$/u';

    /**
     * @param  string  $type  the type key code gave the product
     * @param  string  $source  where code read it: category, store_type, field or title (CodeReading)
     * @param  string|null  $caption  what a model saw in its picture (PictureText::text), null when there is none
     * @return array{type: string, source: string, reasons: list<string>, named: array<string, list<string>>, names: array{product_type: string, categories: list<string>, tags: list<string>}, caption: string|null}|null
     *                                                                                                                                                                                                                     null when the type stands as code read it
     */
    public static function of(CatalogProduct $product, VocabularyDefinition $bank, string $type, string $source, ?string $caption): ?array
    {
        $patterns = self::patterns($bank);
        if (! isset($patterns[$type])) {
            return null;
        }

        // a type from the title stands where its word is what the name says the product is: "מחמם בקבוקים" is a bottle
        // warmer, "הארכה לשער בטיחות" a gate's extension, "בסיס לסלקל" a car seat's base (Shilav, 20.11), no bottle, gate
        // or car seat; a Hebrew name says first what the product is
        if ($source === self::FROM_TITLE) {
            $reason = self::notWhatItIs(TextNormalizer::clean((string) $product->title), $patterns[$type]);

            return $reason === null ? null : ['type' => $type, 'source' => $source, 'reasons' => [$reason], 'named' => [], 'names' => self::names($product),
                'caption' => $caption === null || trim($caption) === '' ? null : $caption];
        }

        // the title says the type's own words: the filing only agreed with it
        if (PatternMatch::any(TextNormalizer::clean((string) $product->title), $patterns[$type])) {
            return null;
        }

        $names = self::names($product);
        $named = [];
        foreach ([...($names['product_type'] === '' ? [] : [$names['product_type']]), ...$names['categories'], ...$names['tags']] as $name) {
            $keys = self::typesNamed($name, $patterns);
            if ($keys !== []) {
                $named[$name] = $keys;
            }
        }
        $distinct = array_values(array_unique(array_merge([], ...array_values($named))));
        sort($distinct, SORT_STRING);

        $reasons = [];
        $caption = $caption === null || trim($caption) === '' ? null : $caption;
        if ($caption === null) {
            $reasons[] = self::NO_CAPTION;
        } elseif (! PatternMatch::any(TextNormalizer::clean($caption), $patterns[$type])) {
            $reasons[] = self::CAPTION_SILENT;
        }
        if (count($distinct) >= 2) {
            $reasons[] = self::TWO_TYPES;
        }
        if (array_any([...($names['product_type'] === '' ? [] : [$names['product_type']]), ...$names['categories']], self::joinsKinds(...))) {
            $reasons[] = self::JOINED_NAME;
        }

        if ($reasons === []) {
            // the picture says the type's words and the filing names one type, in whole names: the type stands
            return null;
        }

        return ['type' => $type, 'source' => $source, 'reasons' => $reasons, 'named' => $named, 'names' => $names, 'caption' => $caption];
    }

    /**
     * Whether a Hebrew title names the type only as what the product is for or belongs to: its word joined to "ל" ("for"),
     * or another Hebrew word before it that does not count what the name holds (COUNTS). Numbers and words in other
     * scripts before it (a brand, a model) say nothing of it. A name in another language says what it is elsewhere
     * ("Backstrap Sandal"), and is left alone.
     *
     * @param  list<string>  $patterns  the type's patterns
     */
    public static function notWhatItIs(string $title, array $patterns): ?string
    {
        $first = null;
        foreach ($patterns as $pattern) {
            foreach (PatternMatch::all($title, $pattern) as [$offset, $length]) {
                if ($first === null || $offset < $first[0]) {
                    $first = [$offset, $length];
                }
            }
        }
        if ($first === null || preg_match('/\p{Hebrew}/u', substr($title, $first[0], $first[1])) !== 1) {
            return null;
        }

        // the letters joined to the word ("ל" in "לבקבוק", "ול" in "ולסלקל"), then the words before them
        preg_match('/(\p{L}*)\z/u', substr($title, 0, $first[0]), $joined);
        if (str_contains($joined[1] ?? '', 'ל')) {
            return self::MADE_FOR;
        }
        $before = substr($title, 0, $first[0] - strlen($joined[1] ?? ''));
        foreach (preg_split('/[^\p{L}\'"׳״]+/u', $before, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if (preg_match('/\p{Hebrew}/u', $word) === 1 && preg_match(self::COUNTS, $word) !== 1) {
                return self::NOT_THE_HEAD;
            }
        }

        return null;
    }

    /**
     * The bank's types a name says, as whole words (PatternMatch): the type keys, in the bank's order.
     *
     * @param  array<string, list<string>>  $patterns  by type key
     * @return list<string>
     */
    public static function typesNamed(string $name, array $patterns): array
    {
        $name = TextNormalizer::clean($name);
        if ($name === '') {
            return [];
        }
        $keys = [];
        foreach ($patterns as $key => $own) {
            if (PatternMatch::any($name, $own)) {
                $keys[] = (string) $key;
            }
        }

        return $keys;
    }

    /**
     * Every type's patterns, by key: those the vocabulary keeps, else made from its words as the bank's are.
     *
     * @return array<string, list<string>>
     */
    public static function patterns(VocabularyDefinition $bank): array
    {
        $out = [];
        foreach ($bank->productTypes() as $type) {
            $patterns = array_values(array_map('strval', (array) ($type['patterns'] ?? [])));
            if ($patterns === []) {
                $patterns = array_map(ExpertBankShape::pattern(...), array_values(array_map('strval', (array) ($type['words'] ?? []))));
            }
            if ($patterns !== []) {
                $out[(string) $type['key']] = $patterns;
            }
        }

        return $out;
    }

    /**
     * The store's own names for a product: its type field, the name of each category it is in (the last of the path),
     * its tags. Each once, in the store's order.
     *
     * @return array{product_type: string, categories: list<string>, tags: list<string>}
     */
    public static function names(CatalogProduct $product): array
    {
        $clean = fn (string $name): string => (string) preg_replace('/^\s+|\s+$/u', '', TextNormalizer::clean($name));
        $categories = [];
        foreach ($product->categoryPaths() as $path) {
            $name = $clean((string) end($path));
            if ($name !== '') {
                $categories[$name] = true;
            }
        }
        $tags = [];
        foreach ((array) ($product->payload['tags'] ?? []) as $tag) {
            $name = $clean((string) $tag);
            if ($name !== '') {
                $tags[$name] = true;
            }
        }

        return [
            'product_type' => $clean((string) ($product->payload['product_type'] ?? '')),
            'categories' => array_map('strval', array_keys($categories)),
            'tags' => array_map('strval', array_keys($tags)),
        ];
    }

    /**
     * A name that joins two kinds or more: "X and Y" in Hebrew (a later word opening with ו, "ו־", "ו-"), "&", "+", "/",
     * " and ". A word that opens with וי or וו is its own word ("וינטג'", "ווסט"), never "and". The search index reads a
     * joined name the same way (BuildSearchIndex::joinsKinds); the two are kept alike.
     */
    public static function joinsKinds(string $name): bool
    {
        if (preg_match('/\s(?:&|\+|and)\s|\//iu', $name) === 1) {
            return true;
        }
        $words = preg_split('/\s+/u', (string) preg_replace('/^\s+|\s+$/u', '', $name)) ?: [];

        return count($words) >= 2 && array_any(array_slice($words, 1), fn (string $w): bool => preg_match('/^ו(?:[־-][א-ת]{2}|[א-הז-טך-ת][א-ת])/u', $w) === 1);
    }
}
