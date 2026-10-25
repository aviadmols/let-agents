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

    /**
     * Whether a word that opens $opens titles is the store's subject rather than a kind among its products: most of
     * the products say it in their title or description, in any form ("פטריה" is said by "פטריות", "הפטריה"), and far
     * more of them than the titles it opens. "פטריה" opens 7 titles of a mushroom store and is said by nearly all of
     * its 46 products ("מיקו דרים" is mushrooms too); "תיק" opens the bags' titles and few others say it.
     */
    public static function subject(string $shopId, string $hash, string $word, int $opens): bool
    {
        $stems = self::stems($shopId, $hash);
        $forms = self::forms($word);

        if ($stems === [] || $forms === []) {
            return false;
        }

        $said = count(array_filter($stems, fn (array $own): bool => array_intersect_key($forms, $own) !== []));

        return $said > count($stems) * self::SUBJECT_SHARE && $said >= 2 * $opens;
    }

    /**
     * Of the products given, those whose title or description says every word of the search that tells products
     * apart: not a word most of the store says (its subject, "פטריה" in a mushroom store), nor one none says. "פטריה
     * לקשב וריכוז" is held by the formula whose description speaks of "מיקוד הקשב" and "ריכוז", in the order given.
     * Empty when no word tells products apart.
     *
     * @param  list<string>  $ids  record ids ("p:<external id>")
     * @return list<string>
     */
    public static function saying(string $shopId, string $hash, string $query, array $ids): array
    {
        $stems = self::stems($shopId, $hash);
        $words = [];

        foreach (array_unique(explode(' ', HebrewSearch::normalize($query))) as $word) {
            $forms = self::forms($word);
            $said = $forms === [] || $stems === [] ? 0 : count(array_filter($stems, fn (array $own): bool => array_intersect_key($forms, $own) !== []));
            if ($said > 0 && $said <= count($stems) * self::SUBJECT_SHARE) {
                $words[] = $forms;
            }
        }

        if ($words === []) {
            return [];
        }

        return array_values(array_filter($ids, fn (string $id): bool => isset($stems[$id])
            && array_all($words, fn (array $forms): bool => array_intersect_key($forms, $stems[$id]) !== [])));
    }

    /** Past this share of the products, a word is the store's subject, not what tells its products apart. */
    public const SUBJECT_SHARE = 0.5;

    /** English words that only join the others: never what tells products apart. */
    private const JOINING = ['the', 'and', 'for', 'with', 'from', 'that', 'this', 'your', 'you', 'are', 'what', 'which', 'some', 'any', 'best', 'good', 'help', 'helps'];

    /**
     * @return array<string, true> a word's stems: a Hebrew one with and without the prefix letters before it ("לקשב":
     *                             קשב), an English one without its ending ("mushrooms", "mushroom": mushroom; "focusing": focus)
     */
    private static function forms(string $word): array
    {
        if (preg_match('/^[a-z]{3,}$/', $word) === 1) {
            return in_array($word, self::JOINING, true) ? [] : [self::english($word) => true];
        }
        if (preg_match('/^[א-ת]{2,}$/u', $word) !== 1) {
            return [];
        }

        $forms = [HebrewSearch::stem($word) => true];
        for ($cut = 1; $cut <= 2 && mb_strlen($word) - $cut >= 3 && preg_match('/^'.self::PREFIX.'$/u', mb_substr($word, $cut - 1, 1)) === 1; $cut++) {
            $forms[HebrewSearch::stem(mb_substr($word, $cut))] = true;
        }

        return $forms;
    }

    /** "sleeping", "sleeps", "sleep": sleep; "remedies": remedy. Only an ending that leaves four letters or more. */
    private static function english(string $word): string
    {
        foreach (['ies' => 'y', 'ing' => '', 'ed' => '', 'es' => '', 's' => ''] as $ending => $instead) {
            if (str_ends_with($word, $ending) && strlen($word) - strlen($ending) >= 4 && ! str_ends_with($word, 'ss')) {
                return substr($word, 0, -strlen($ending)).$instead;
            }
        }

        return $word;
    }

    /** @return array<string, array<string, true>> record id => the stems of its title and description words */
    private static function stems(string $shopId, string $hash): array
    {
        return Cache::remember('search:described-stems:'.$shopId.':'.$hash, now()->addDay(), function (): array {
            $stems = [];

            foreach (CatalogProduct::query()->active()->orderBy('id')->lazy(200) as $product) {
                $payload = (array) $product->payload;
                $raw = html_entity_decode(strip_tags((string) $product->title.' '.(string) ($payload['short_description'] ?? '').' '.(string) ($payload['description'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $own = [];
                foreach (array_unique(explode(' ', HebrewSearch::normalize(mb_substr($raw, 0, self::CHARS)))) as $word) {
                    $own += self::forms($word);
                }
                $stems['p:'.$product->external_id] = $own;
            }

            return $stems;
        });
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
