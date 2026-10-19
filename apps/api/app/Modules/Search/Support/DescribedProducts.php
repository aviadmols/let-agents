<?php

namespace App\Modules\Search\Support;

use App\Modules\Catalog\Models\CatalogProduct;
use Illuminate\Support\Facades\Cache;

/**
 * Products whose own description says what was searched, as a phrase: "7 באוקטובר" finds the books
 * about the seventh of October that only their description names, not their title or tags. Server-side
 * only (descriptions never go to the browser's index), and a second tier: SearchCatalog asks for them
 * only when the words found few products, and ranks them after what the words found.
 *
 * Each word may carry a Hebrew prefix (ו, ה, ב, ל, מ, ש, כ, also standing alone after "ה-7"), and a
 * number word is also its digits ("שבע", "השבעה": 7). The words must come in the order typed, next to
 * each other: "7" and "אוקטובר" far apart in a text are not the date.
 */
final class DescribedProducts
{
    /** Below this many products found by the words, the descriptions are searched too. */
    public const FEW = 24;

    /** At most this many products found by their description. */
    public const LIMIT = 60;

    /** How much of a description is read: its opening says what it is about. */
    private const CHARS = 4000;

    private const PREFIX = '[והבלמשכ]';

    /**
     * @param  string  $hash  the search index's hash: a rebuilt index reads the descriptions again
     * @return list<string> record ids ("p:<external id>"), the earliest mention first
     */
    public static function find(string $shopId, string $hash, string $query): array
    {
        $pattern = self::pattern($query);

        if ($pattern === null) {
            return [];
        }

        $found = [];

        foreach (self::texts($shopId, $hash) as $id => $text) {
            if (preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE) === 1) {
                $found[$id] = $m[0][1];
            }
        }

        asort($found);

        return array_slice(array_map('strval', array_keys($found)), 0, self::LIMIT);
    }

    /** The phrase as a pattern over normalized text, or null when it is too short to mean anything. */
    public static function pattern(string $query): ?string
    {
        $words = array_values(array_filter(explode(' ', HebrewSearch::normalize($query)), fn (string $w): bool => $w !== ''));

        if ($words === [] || mb_strlen(implode('', $words)) < 3 || count($words) > 6) {
            return null;
        }

        $parts = [];

        foreach ($words as $word) {
            $forms = [preg_quote($word, '/')];
            // "באוקטובר" is also "אוקטובר" with any prefix in the text ("לאוקטובר", "אוקטובר").
            if (preg_match('/^'.self::PREFIX.'{1,2}[א-ת]{3,}$/u', $word) === 1) {
                $forms[] = preg_quote((string) preg_replace('/^'.self::PREFIX.'{1,2}/u', '', $word), '/');
            }
            // a number in digits or in words is all its forms: "7", "שבע", "השבעה" (the ה is a prefix here)
            $digits = HebrewSearch::numberOf($word) ?? (preg_match('/^\d+$/', $word) === 1 ? $word : null);
            if ($digits !== null) {
                array_push($forms, $digits, ...HebrewSearch::numberWords($digits));
            }
            $parts[] = '(?:'.self::PREFIX.' )?'.self::PREFIX.'{0,3}(?:'.implode('|', array_unique($forms)).')';
        }

        return '/ '.implode(' ', $parts).' /u';
    }

    /** @return array<string, string> record id => its description, normalized and padded with spaces */
    private static function texts(string $shopId, string $hash): array
    {
        return Cache::remember('search:described:'.$shopId.':'.$hash, now()->addDay(), function (): array {
            $texts = [];

            foreach (CatalogProduct::query()->active()->orderBy('id')->lazy(200) as $product) {
                $payload = (array) $product->payload;
                $raw = html_entity_decode(strip_tags((string) ($payload['short_description'] ?? '').' '.(string) ($payload['description'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $text = HebrewSearch::normalize(mb_substr($raw, 0, self::CHARS));

                if ($text !== '') {
                    $texts['p:'.$product->external_id] = ' '.$text.' ';
                }
            }

            return $texts;
        });
    }
}
