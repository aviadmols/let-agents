<?php

namespace App\Modules\Search\Support;

/**
 * What a question asked on a product's page wants of its alike besides being alike: "יש טבעת דומה עם יהלום והרבה
 * יותר יקרה?" is a ring like this one that says "יהלום", at half again its price at least. Read in code, no model:
 * the price as more or less than this product's, and the other words as words its alike must say (title and
 * keywords). A word no product of the store says is not a wish ("בבקשה", a typo), and the words of asking for the
 * alike, of its kind, and of another colour (SearchCatalog::otherColour reads that) are not either.
 */
final class SimilarWants
{
    /** "much more expensive": at least this many times its price, or this share of it for "much cheaper". */
    public const MUCH = 1.5;

    private const DEARER = '/(?:יותר\s+יקר\S*|יקר(?:ה|ים|ות)?\s+יותר|ביוקר|more\s+expensive|pricier|dearer|higher[\s-]+priced|more\s+premium)/iu';

    private const CHEAPER = '/(?:יותר\s+זול\S*|זול(?:ה|ים|ות)?\s+יותר|פחות\s+יקר\S*|בזול|cheaper|less\s+expensive|lower[\s-]+priced|more\s+affordable)/iu';

    private const MUCH_WORDS = '/(?:^|\s)(?:ו?הרבה|בהרבה|משמעותית|much|far|way|a\s+lot|significantly)(?=\s|$)/iu';

    /** The words of asking for its alike, and for another colour or stone, normalized. */
    private const ASKING = ['דומה', 'דומות', 'דומימ', 'דומי', 'כמו', 'מקביל', 'מקבילה', 'זאת', 'הזה', 'הזאת', 'אחר', 'אחרת', 'אחרימ', 'אחרות',
        'שונה', 'שונימ', 'צבע', 'בצבע', 'בצע', 'בצבעימ', 'גוונ', 'אבנ', 'אבנימ', 'באבנ', 'עוד', 'יותר', 'פחות', 'הרבה', 'בהרבה',
        'similar', 'alike', 'like', 'this', 'one', 'ones', 'alternative', 'alternatives', 'other', 'another', 'different', 'colour',
        'color', 'colours', 'colors', 'stone', 'stones', 'more', 'less', 'any', 'there', 'have', 'you', 'got', 'in'];

    /**
     * @param  array<string, array<string, mixed>>  $records  the index's records, to know which words the store says
     * @param  array<string, mixed>|null  $own  the product's own record
     * @return array{words: list<string>, price: 'up'|'down'|null, much: bool}
     */
    public static function read(string $asked, array $records, ?array $own): array
    {
        $price = preg_match(self::DEARER, $asked) === 1 ? 'up' : (preg_match(self::CHEAPER, $asked) === 1 ? 'down' : null);
        $much = $price !== null && preg_match(self::MUCH_WORDS, $asked) === 1;
        $text = (string) preg_replace([self::DEARER, self::CHEAPER], ' ', $asked);
        $kinds = array_filter([HebrewSearch::head((string) ($own['title'] ?? '')), ...array_map('strval', (array) ($own['kd'] ?? []))]);

        $said = null;
        $words = [];
        foreach (array_unique(explode(' ', HebrewSearch::normalize($text))) as $word) {
            if (mb_strlen($word) < 2 || HebrewSearch::isFiller($word) || in_array($word, self::ASKING, true) || is_numeric($word)) {
                continue;
            }
            // "עם יהלום" and "ביהלום" alike: the word, or the word after its prefix letter, as the store says it
            $forms = preg_match('/^[והבלמשכ][א-ת]{3,}$/u', $word) === 1 ? [$word, mb_substr($word, 1)] : [$word];
            $said ??= array_map(fn (array $r): string => (string) ($r['title'] ?? '').' '.(string) ($r['kw'] ?? ''), $records);
            foreach ($forms as $form) {
                if (in_array($form, self::ASKING, true) || in_array(HebrewSearch::stem($form), $kinds, true)) {
                    continue 2;
                }
                foreach ($said as $text) {
                    if (HebrewSearch::says($text, $form)) {
                        $words[] = HebrewSearch::withFinals($form);

                        continue 3;
                    }
                }
            }
        }

        return ['words' => $words, 'price' => $price, 'much' => $much];
    }

    /** @param  array{words: list<string>, price: 'up'|'down'|null, much: bool}  $wants */
    public static function any(array $wants): bool
    {
        return $wants['words'] !== [] || $wants['price'] !== null;
    }

    /**
     * Its alike that are all it asked: every word said, and the price on the side asked of its own.
     *
     * @param  list<array<string, mixed>>  $records
     * @param  array{words: list<string>, price: 'up'|'down'|null, much: bool}  $wants
     * @param  array<string, mixed>|null  $own
     * @return list<array<string, mixed>>
     */
    public static function apply(array $records, array $wants, ?array $own): array
    {
        $ownPrice = isset($own['pr']) ? (float) $own['pr'] : null;
        $factor = $wants['much'] ? self::MUCH : 1.0;

        return array_values(array_filter($records, function (array $r) use ($wants, $ownPrice, $factor): bool {
            $text = (string) ($r['title'] ?? '').' '.(string) ($r['kw'] ?? '');
            foreach ($wants['words'] as $word) {
                if (! HebrewSearch::says($text, $word)) {
                    return false;
                }
            }
            if ($wants['price'] === null) {
                return true;
            }
            if ($ownPrice === null || $ownPrice <= 0 || ! isset($r['pr'])) {
                return false;
            }

            return $wants['price'] === 'up' ? (float) $r['pr'] >= $ownPrice * $factor && (float) $r['pr'] > $ownPrice : (float) $r['pr'] <= $ownPrice / $factor && (float) $r['pr'] < $ownPrice;
        }));
    }
}
