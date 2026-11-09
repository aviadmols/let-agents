<?php

namespace App\Modules\Assistant\Support;

use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use Illuminate\Support\Facades\Cache;

/**
 * A word the shopper misspelled, read as the store's own word it is closest to ("lawyawy" for the store's "Layaway"),
 * by code and before any model: the store's words are every word of its pages, its knowledge and its products' titles.
 * The topic check is then shown what the store writes about the word it meant (Rocks, 9.11: "מה זאת תוכנית lawyawy?"
 * was refused as not about the store, its Layaway page too far by meaning from the misspelling). Nothing is replaced in
 * the question the shopper asked; only what is looked up beside it. Asked inside the shop.
 */
final class StoreSpelling
{
    /** Words shorter than this are never read as another: too many short words are a letter apart. */
    private const MIN_CHARS = 5;

    /** From this length a word may be two letters off ("lawyawy" → "layaway"); shorter, one. */
    private const TWO_OFF_FROM = 7;

    /** The store's words kept, the most said first. */
    private const MAX_WORDS = 20000;

    private const CACHE_HOURS = 24;

    /** Each letter as one byte, so PHP's levenshtein() counts Hebrew letters, not their bytes. */
    private static array $letters = [];

    /**
     * The question with each misspelled word as the store's word it is closest to; null when no word changed.
     */
    public static function fix(string $shopId, string $question): ?string
    {
        $words = self::words($shopId);
        if ($words === []) {
            return null;
        }

        $changed = false;
        $fixed = preg_replace_callback('/\p{L}+/u', function (array $m) use ($words, &$changed): string {
            $closest = self::closest($m[0], $words);
            if ($closest === null) {
                return $m[0];
            }
            $changed = true;

            return $closest;
        }, $question);

        return $changed && is_string($fixed) ? $fixed : null;
    }

    /**
     * The store's word one or two letters from $word, the most said of the nearest; null when $word is the store's own
     * already, too short, or near none.
     *
     * @param  array<string, array{0: string, 1: int}>  $words  normalized => [as written, how often]
     */
    public static function closest(string $word, array $words): ?string
    {
        $key = self::normalize($word);
        $length = mb_strlen($key);

        if ($length < self::MIN_CHARS || isset($words[$key])) {
            return null;
        }

        $most = $length >= self::TWO_OFF_FROM ? 2 : 1;
        $typed = self::bytes($key);
        if ($typed === null) {
            return null;
        }

        $best = null;
        foreach ($words as $candidate => [$written, $count]) {
            $candidateLength = mb_strlen((string) $candidate);
            if ($candidateLength < self::MIN_CHARS || abs($candidateLength - $length) > $most) {
                continue;
            }
            $other = self::bytes((string) $candidate);
            if ($other === null) {
                continue;
            }
            $distance = levenshtein($typed, $other);
            if ($distance <= $most && ($best === null || [$distance, -$count, $candidate] < [$best[0], -$best[2], $best[3]])) {
                $best = [$distance, $written, $count, (string) $candidate];
            }
        }

        return $best === null ? null : $best[1];
    }

    /**
     * Every word the store writes, normalized, with the way it is written and how often: its pages and knowledge (title,
     * excerpt, body) and its products' titles. Kept for a day, and read again as soon as any of them changes.
     *
     * @return array<string, array{0: string, 1: int}>
     */
    public static function words(string $shopId): array
    {
        $stamp = implode('|', [
            CatalogContent::query()->active()->count(), (string) CatalogContent::query()->active()->max('updated_at'),
            CatalogProduct::query()->active()->count(), (string) CatalogProduct::query()->active()->max('updated_at'),
        ]);

        return Cache::remember('assistant.store_spelling.'.$shopId.'.'.md5($stamp), now()->addHours(self::CACHE_HOURS), function (): array {
            $counts = [];
            $add = function (?string $text) use (&$counts): void {
                preg_match_all('/\p{L}+/u', (string) $text, $m);
                foreach ($m[0] as $written) {
                    $key = self::normalize($written);
                    if (mb_strlen($key) < self::MIN_CHARS) {
                        continue;
                    }
                    $counts[$key] ??= [$written, 0];
                    $counts[$key][1]++;
                }
            };

            CatalogContent::query()->active()->select(['id', 'title', 'excerpt', 'body'])->chunkById(200, function ($pages) use ($add): void {
                foreach ($pages as $page) {
                    $add($page->title);
                    $add($page->excerpt);
                    $add(strip_tags((string) $page->body));
                }
            });
            CatalogProduct::query()->active()->select(['id', 'title'])->chunkById(500, function ($products) use ($add): void {
                foreach ($products as $product) {
                    $add($product->title);
                }
            });

            // the most said first, then by the word, so the same store reads the same list on every server
            uksort($counts, fn (string $a, string $b): int => [$counts[$b][1], $a] <=> [$counts[$a][1], $b]);

            return array_slice($counts, 0, self::MAX_WORDS, true);
        });
    }

    /** Lower case, and Hebrew's final letters as their usual form ("ם" as "מ"): "Layaway" and "layaway" are one word. */
    public static function normalize(string $word): string
    {
        return strtr(mb_strtolower($word), ['ך' => 'כ', 'ם' => 'מ', 'ן' => 'נ', 'ף' => 'פ', 'ץ' => 'צ']);
    }

    /** The word with each letter as one byte, for levenshtein(); null past 250 different letters (never in practice). */
    private static function bytes(string $word): ?string
    {
        $out = '';
        foreach (preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $letter) {
            if (! isset(self::$letters[$letter])) {
                if (count(self::$letters) >= 250) {
                    return null;
                }
                self::$letters[$letter] = chr(count(self::$letters) + 1);
            }
            $out .= self::$letters[$letter];
        }

        return $out;
    }
}
