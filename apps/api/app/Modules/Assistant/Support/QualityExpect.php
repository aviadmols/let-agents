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
     * @param  array{outcome?: string, contains?: list<string>, absent?: list<string>, picks?: list<string>}  $expect
     * @param  array<string, mixed>  $result  what AnswerSiteQuestion answered (outcome, answer, picks)
     * @return list<string> what failed, empty when it passed
     */
    public static function problems(array $expect, array $result): array
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

        return $problems;
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
