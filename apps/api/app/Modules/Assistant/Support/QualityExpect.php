<?php

namespace App\Modules\Assistant\Support;

/**
 * What a regression case expects of an answer, checked in code, never by a model: its outcome, words
 * it must say (any of them), words it must not say (none of them), and products it must offer (any of
 * them). A word matches as written, case and spacing aside; an empty expectation is not checked.
 */
final class QualityExpect
{
    /**
     * Beyond the words: the products it must offer (any of them), the pages it must rest on (any of them,
     * by address), and whether the checker of the other family found it supported by the store's own
     * data (its verdict "kept"): words can all be there and the answer still be wrong.
     *
     * Beyond its words, the picks against the catalogue: picks_max_price and picks_min_price (every pick's price within;
     * a reply that keeps the conversation's budget), picks_title_any (every pick's title says one of the words),
     * picks_say_any (its title or description does: "זהב צהוב" is in a ring's description, not its title), picks_max
     * (no more than this many).
     *
     * @param  array{outcome?: string, contains?: list<string>, absent?: list<string>, picks?: list<string>, sources?: list<string>, supported?: bool, picks_max_price?: float, picks_min_price?: float, picks_title_any?: list<string>, picks_say_any?: list<string>, picks_max?: int}  $expect
     * @param  array<string, mixed>  $result  what AnswerSiteQuestion answered (outcome, answer, picks, sources)
     * @param  string|null  $verdict  the checker's verdict on it
     * @param  array<string, array{title?: string, price?: float|null, text?: string}>  $catalog  the picked products, by external id
     * @return list<string> what failed, empty when it passed
     */
    public static function problems(array $expect, array $result, ?string $verdict = null, array $catalog = []): array
    {
        $answer = self::norm((string) ($result['answer'] ?? ''));
        $picks = array_map(fn ($p): string => (string) (is_array($p) ? ($p['external_id'] ?? '') : $p), (array) ($result['picks'] ?? []));
        $problems = [];

        if (($expect['outcome'] ?? '') !== '' && ($result['outcome'] ?? null) !== $expect['outcome']) {
            $problems[] = 'outcome:'.($result['outcome'] ?? '');
        }

        $contains = self::words($expect['contains'] ?? []);
        if ($contains !== [] && array_filter($contains, fn (string $w): bool => str_contains($answer, $w)) === []) {
            $problems[] = 'missing:'.implode('|', $contains);
        }

        foreach (self::words($expect['absent'] ?? []) as $word) {
            if (str_contains($answer, $word)) {
                $problems[] = 'said:'.$word;
            }
        }

        $wanted = array_values(array_filter(array_map('strval', (array) ($expect['picks'] ?? []))));
        if ($wanted !== [] && array_intersect($wanted, $picks) === []) {
            $problems[] = 'picks';
        }

        $pages = array_values(array_filter(array_map(fn ($u): string => self::page((string) $u), (array) ($expect['sources'] ?? []))));
        $cited = array_map(fn ($s): string => self::page((string) (is_array($s) ? ($s['url'] ?? '') : $s)), (array) ($result['sources'] ?? []));
        if ($pages !== [] && array_intersect($pages, $cited) === []) {
            $problems[] = 'sources';
        }

        if (($expect['supported'] ?? false) === true && $verdict !== 'kept') {
            $problems[] = 'unsupported:'.($verdict ?? 'unchecked');
        }

        if (isset($expect['picks_max']) && count($picks) > (int) $expect['picks_max']) {
            $problems[] = 'picks_over:'.count($picks);
        }

        foreach ($picks as $id) {
            $product = $catalog[$id] ?? [];
            $price = isset($product['price']) ? (float) $product['price'] : null;
            $title = self::norm((string) ($product['title'] ?? ''));
            $says = $title.' '.self::norm((string) ($product['text'] ?? ''));

            if (isset($expect['picks_max_price']) && ($price === null || $price > (float) $expect['picks_max_price'])) {
                $problems[] = 'price_over:'.$id.($price === null ? '' : ':'.$price);
            }
            if (isset($expect['picks_min_price']) && ($price === null || $price < (float) $expect['picks_min_price'])) {
                $problems[] = 'price_under:'.$id.($price === null ? '' : ':'.$price);
            }
            if (($words = self::words($expect['picks_title_any'] ?? [])) !== [] && array_filter($words, fn (string $w): bool => str_contains($title, $w)) === []) {
                $problems[] = 'pick_title:'.$id;
            }
            if (($words = self::words($expect['picks_say_any'] ?? [])) !== [] && array_filter($words, fn (string $w): bool => str_contains($says, $w)) === []) {
                $problems[] = 'pick_says:'.$id;
            }
        }

        return $problems;
    }

    /** An address as a page: no scheme, no "www.", no query, no trailing slash. */
    private static function page(string $url): string
    {
        $url = mb_strtolower(trim($url));
        $url = (string) preg_replace('~^https?://(www\.)?|[?#].*$~u', '', $url);

        return rtrim($url, '/');
    }

    /**
     * "יריעה, פריימר" as the words a case keeps.
     *
     * @return list<string>
     */
    public static function split(string $words): array
    {
        return array_values(array_filter(array_map(fn (string $w): string => (string) preg_replace('/^\s+|\s+$/u', '', $w), preg_split('/[,\n]+/u', $words) ?: []), fn (string $w): bool => $w !== ''));
    }

    /**
     * @return list<string>
     */
    private static function words(mixed $words): array
    {
        return array_values(array_filter(array_map(fn ($w): string => self::norm((string) $w), (array) $words), fn (string $w): bool => $w !== ''));
    }

    private static function norm(string $text): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', ' ', preg_replace('/^\s+|\s+$/u', '', $text) ?? ''));
    }
}
