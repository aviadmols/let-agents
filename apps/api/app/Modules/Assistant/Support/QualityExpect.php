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
     * @param  array{outcome?: string, contains?: list<string>, absent?: list<string>, picks?: list<string>, sources?: list<string>, supported?: bool}  $expect
     * @param  array<string, mixed>  $result  what AnswerSiteQuestion answered (outcome, answer, picks, sources)
     * @param  string|null  $verdict  the checker's verdict on it
     * @return list<string> what failed, empty when it passed
     */
    public static function problems(array $expect, array $result, ?string $verdict = null): array
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
