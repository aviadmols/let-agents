<?php

namespace App\Modules\Retrieval\Support;

/**
 * What a section of a page or post, or a question, is about, from the words that name each topic
 * (resources/topics.json): "אפשר להחזיר טבעת?" and a section headed "מדיניות החזרות" are both about
 * returns, though they share no word. A question finds the sections of its topic as well as the
 * nearest by meaning (PassageSearch), so the return policy is found however the shopper asks.
 *
 * A section is about a topic its heading names once or its text names twice; a question, once.
 * Code only, no model.
 */
final class Topics
{
    private const FILE = __DIR__.'/../resources/topics.json';

    /** Times the text of a section names a topic before it is about it; its heading, once. */
    private const SECTION_TIMES = 2;

    /** @return list<string> the topics of a question, sorted */
    public static function ofQuestion(string $question): array
    {
        return array_keys(array_filter(self::counts($question), fn (int $n): bool => $n >= 1));
    }

    /** @return list<string> the topics of a section, sorted */
    public static function ofSection(string $title, string $text): array
    {
        // The first line of a piece is often its heading ("## מדיניות החזרות"); a short one counts as one.
        $first = (string) strtok(ltrim($text), "\n");
        $heading = $title.(str_starts_with($first, '#') || mb_strlen($first) <= 80 ? "\n".$first : '');
        $named = self::counts($heading);
        $said = self::counts($text);

        $topics = array_keys(array_filter($said + $named, fn (int $n, string $topic): bool => ($named[$topic] ?? 0) >= 1 || ($said[$topic] ?? 0) >= self::SECTION_TIMES, ARRAY_FILTER_USE_BOTH));
        sort($topics);

        return $topics;
    }

    /** @param  list<string>  $topics  @return string|null " returns shipping ", searched with LIKE "% returns %" */
    public static function column(array $topics): ?string
    {
        sort($topics);

        return $topics === [] ? null : ' '.implode(' ', $topics).' ';
    }

    /** @return list<string> */
    public static function fromColumn(?string $column): array
    {
        return array_values(array_filter(explode(' ', trim((string) $column))));
    }

    /** @return array<string, int> times each topic is named, by topic, sorted by topic */
    private static function counts(string $text): array
    {
        $text = ' '.mb_strtolower((string) preg_replace('/\s+/u', ' ', $text)).' ';
        $counts = [];

        foreach (self::registry() as $topic => $words) {
            $n = 0;
            foreach ($words as $word) {
                $n += (int) preg_match_all('/(?<![\p{L}\p{N}])[והבלמשכ]{0,2}'.preg_quote(mb_strtolower($word), '/').'/u', $text);
            }
            if ($n > 0) {
                $counts[$topic] = $n;
            }
        }

        ksort($counts);

        return $counts;
    }

    /** @return array<string, list<string>> */
    private static function registry(): array
    {
        static $registry = null;

        return $registry ??= array_map(fn ($words): array => array_values((array) $words), (array) (json_decode((string) file_get_contents(self::FILE), true)['topics'] ?? []));
    }
}
