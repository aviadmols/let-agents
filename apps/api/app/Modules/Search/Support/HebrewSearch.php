<?php

namespace App\Modules\Search\Support;

/**
 * Typo-tolerant Hebrew search without a model: word vectors made of three-letter pieces, plus a
 * spelling stem and a sound key, compared by cosine.
 *
 * The algorithm, its thresholds and its weights come unchanged from the search that was built and
 * measured on the pilot store (docs/search-mechanism.md there). resources/search/let-agents-search.js
 * runs the same algorithm in the browser for instant suggestions; the two must return the same
 * ids in the same order, so a change here is a change there, and SearchEngineParityTest checks
 * both against the same fixture.
 *
 *   $index = HebrewSearch::build($items);        // [['id' => 'p:12', 'title' => …, 'keywords' => …], …]
 *   $hits  = HebrewSearch::search($index, 'מקיטא'); // [['id' => …, 'title' => …, 'score' => …], …] best first
 */
final class HebrewSearch
{
    private const FINALS = ['ך' => 'כ', 'ם' => 'מ', 'ן' => 'נ', 'ף' => 'פ', 'ץ' => 'צ'];

    private const ALIKE = ['ט' => 'ת', 'ק' => 'כ', 'ח' => 'כ', 'ע' => 'א', 'ס' => 'ש', 'ה' => 'א'];

    private const KEYBOARD = [
        'e' => 'ק', 'r' => 'ר', 't' => 'א', 'y' => 'ט', 'u' => 'ו', 'i' => 'נ', 'o' => 'מ', 'p' => 'פ',
        'a' => 'ש', 's' => 'ד', 'd' => 'ג', 'f' => 'כ', 'g' => 'ע', 'h' => 'י', 'j' => 'ח', 'k' => 'ל', 'l' => 'כ', ';' => 'פ',
        'z' => 'ז', 'x' => 'ס', 'c' => 'ב', 'v' => 'ה', 'b' => 'נ', 'n' => 'מ', 'm' => 'צ', ',' => 'ת', '.' => 'צ',
    ];

    /** Words a question wraps around what it is about ("מה הכי טוב ל…"). */
    private const FILLER = ['איך', 'כמה', 'האם', 'למה', 'מדוע', 'מה', 'מהו', 'מהי', 'מהמ', 'איפה', 'היכנ', 'מתי', 'מי', 'איזה', 'איזו', 'אילו',
        'אפשר', 'ניתנ', 'יש', 'צריכ', 'כדאי', 'מותר', 'מתאימ', 'מתאימה', 'מתאימימ', 'הכי', 'לי', 'לנו', 'עמ', 'של', 'את', 'על', 'ליד', 'זה', 'זו',
        'טוב', 'טובה', 'לקנות', 'לבחור', 'בשביל', 'או', 'גמ', 'the', 'a', 'an', 'to', 'for', 'of', 'with', 'best', 'need', 'buy', 'i', 'my',
        'how', 'what', 'why', 'when', 'where', 'which', 'who', 'can', 'does', 'do', 'is', 'are', 'should'];

    /**
     * What a sentence is about, in the catalogue's words: question and filler words dropped, then
     * every word no product or page holds anything close to ("ביתית", "וזולה"). "מה הכי טוב
     * למברגה ביתית וזולה" is "למברגה". Empty when nothing is left.
     */
    public static function aboutWords(array $index, string $query): string
    {
        $words = array_filter(explode(' ', self::normalize($query)), fn (string $w): bool => $w !== '' && ! in_array($w, self::FILLER, true));

        return implode(' ', array_filter($words, fn (string $w): bool => mb_strlen($w) >= 2 && self::closeWords($index, $w) !== []));
    }

    /**
     * What a question asks about: its first word that is not a question or filler word and that
     * the catalogue knows. "איזה עץ מתאים לבנית פרגולה בחוץ" asks about "עץ"; the pergola is
     * what it is for. Null when nothing is left.
     */
    public static function subject(array $index, string $query): ?string
    {
        foreach (explode(' ', self::normalize($query)) as $word) {
            if ($word !== '' && mb_strlen($word) >= 2 && ! in_array($word, self::FILLER, true) && self::closeWords($index, $word) !== []) {
                return $word;
            }
        }

        return null;
    }

    /** Whether a query is worded as a question: it holds a question or filler word ("מה הכי טוב"). */
    public static function asks(string $query): bool
    {
        return array_intersect(explode(' ', self::normalize($query)), self::FILLER) !== [];
    }

    /** A word scores at least this to count as close to what was typed. */
    public const FLOOR = 0.45;

    /** Kept only when at least this share of the best close word's score. */
    public const RELATIVE_FLOOR = 0.8;

    public static function normalize(?string $text): string
    {
        $text = mb_strtolower((string) $text, 'UTF-8');
        $text = (string) preg_replace('/[\x{0591}-\x{05C7}]/u', '', $text);
        $text = (string) preg_replace('/[\x{05F3}\x{05F4}\'"`]/u', '', $text);
        $text = strtr($text, self::FINALS);

        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text));
    }

    /** ברגים and בורג are both ברג. */
    public static function stem(string $word): string
    {
        if (! preg_match('/^[א-ת]+$/u', $word) || mb_strlen($word) < 3) {
            return $word;
        }

        $base = mb_strlen($word) > 4 ? (string) preg_replace('/(ימ|ות)$/u', '', $word) : $word;

        return mb_substr($base, 0, 1).preg_replace('/[וי]/u', '', mb_substr($base, 1));
    }

    public static function sound(string $word): string
    {
        return strtr(self::stem($word), self::ALIKE);
    }

    /** @return array<string, float> three-letter pieces of the word and of its sound, length 1 */
    public static function wordVector(string $word): array
    {
        $vector = [];
        $heard = self::sound($word);
        $sides = $heard !== $word ? [$word, '~'.$heard] : [$word];

        foreach ($sides as $side) {
            $letters = preg_split('//u', ' '.$side.' ', -1, PREG_SPLIT_NO_EMPTY) ?: [];

            for ($i = 0, $n = count($letters) - 2; $i < $n; $i++) {
                $piece = $letters[$i].$letters[$i + 1].$letters[$i + 2];
                $vector[$piece] = ($vector[$piece] ?? 0) + 1;
            }
        }

        $length = sqrt(array_sum(array_map(static fn ($w) => $w * $w, $vector))) ?: 1.0;

        return array_map(static fn ($w): float => $w / $length, $vector);
    }

    /** "ncrdv" typed on an English keyboard is מברגה. Empty when the query is not all Latin keys. */
    public static function fromKeyboard(string $query): string
    {
        if (! preg_match('/^[a-z;,. ]+$/', $query) || ! preg_match('/[a-z]/', $query)) {
            return '';
        }

        return strtr($query, self::KEYBOARD);
    }

    /**
     * @param  iterable<array{id: string, title: string, keywords?: string|null}>  $items
     * @return array{items: list<array{id: string, title: string, name: string, named: string, hay: string}>, list: list<string>, holders: array<string, list<int>>, postings: array<string, list<array{0: int, 1: float}>>}
     */
    public static function build(iterable $items): array
    {
        $records = [];
        $holders = [];
        $list = [];
        $postings = [];

        foreach ($items as $item) {
            $at = count($records);
            $name = self::normalize($item['title']);
            $hay = self::normalize($item['title'].' '.($item['keywords'] ?? ''));
            $records[] = ['id' => (string) $item['id'], 'title' => (string) $item['title'], 'name' => $name, 'named' => ' '.$name.' ', 'hay' => $hay];

            foreach (explode(' ', $hay) as $word) {
                if ($word === '') {
                    continue;
                }

                if (! isset($holders[$word])) {
                    $holders[$word] = [];
                    $list[] = (string) $word;
                }

                if (end($holders[$word]) !== $at) {
                    $holders[$word][] = $at;
                }
            }
        }

        foreach ($list as $w => $word) {
            foreach (self::wordVector($word) as $piece => $weight) {
                $postings[$piece][] = [$w, $weight];
            }
        }

        return ['items' => $records, 'list' => $list, 'holders' => $holders, 'postings' => $postings];
    }

    /**
     * Catalogue words close to one typed word, with how close (1 = the word itself).
     *
     * @return array<string, float>
     */
    public static function closeWords(array $index, string $typed): array
    {
        $found = [];
        $variants = [$typed];

        // לעץ is also checked as עץ.
        if (preg_match('/^[והבלמשכ]/u', $typed) && mb_strlen($typed) > 3) {
            $variants[] = mb_substr($typed, 1);
        }

        foreach ($variants as $position => $variant) {
            $scores = [];
            $base = self::stem($variant);
            $length = mb_strlen($variant);

            foreach (self::wordVector($variant) as $piece => $weight) {
                foreach ($index['postings'][$piece] ?? [] as [$w, $other]) {
                    $scores[$w] = ($scores[$w] ?? 0) + $weight * $other;
                }
            }

            foreach ($index['list'] as $w => $candidate) {
                $candidate = (string) $candidate;
                $score = $scores[$w] ?? 0;

                if ($candidate === $variant) {
                    $score = 1;
                } elseif ($length >= 2 && str_starts_with($candidate, $variant)) {
                    $score = max($score, 0.9 - 0.02 * (mb_strlen($candidate) - $length));
                } elseif ($length >= 3 && self::stem($candidate) === $base) {
                    $score = max($score, 0.85);
                } elseif (mb_strlen($candidate) > 3 && preg_match('/^[והבלמשכ]/u', $candidate) && mb_substr($candidate, 1) === $variant) {
                    $score = max($score, 0.85);
                }

                if ($position) {
                    $score *= 0.95;
                }

                if ($score >= self::FLOOR && ($found[$candidate] ?? 0) < $score) {
                    $found[$candidate] = $score;
                }
            }
        }

        $floor = $found ? max(self::FLOOR, max($found) * self::RELATIVE_FLOOR) : self::FLOOR;

        return array_filter($found, static fn ($score): bool => $score >= $floor);
    }

    /** 0 (best) to 5 when the record holds every word as typed; -1 when it does not. */
    public static function exactTier(array $record, string $query, array $tokens): int
    {
        foreach ($tokens as $token) {
            if (! str_contains($record['hay'], $token)) {
                return -1;
            }
        }

        return match (true) {
            $record['name'] === $query => 0,
            str_starts_with($record['name'], $query) => 1,
            str_contains(' '.$record['name'], ' '.$query) => 2,
            str_contains($record['name'], $query) => 3,
            default => self::allTokensStartWords($record['name'], $tokens) ? 4 : 5,
        };
    }

    /**
     * @return list<array{id: string, title: string, score: float, exact: bool}>
     */
    public static function rank(array $index, string $query): array
    {
        $tokens = explode(' ', $query);
        $score = [];
        $hits = [];
        $found = [];
        $needed = count($tokens) <= 2 ? count($tokens) : count($tokens) - 1;

        foreach ($tokens as $token) {
            $best = [];

            foreach (self::closeWords($index, $token) as $word => $closeness) {
                foreach ($index['holders'][$word] ?? [] as $at) {
                    // A word in the name counts for more than one in the keywords.
                    $value = $closeness * (str_contains($index['items'][$at]['named'], ' '.$word.' ') ? 1 : 0.7);

                    if (($best[$at] ?? 0) < $value) {
                        $best[$at] = $value;
                    }
                }
            }

            foreach ($best as $at => $value) {
                $score[$at] = ($score[$at] ?? 0) + $value;
                $hits[$at] = ($hits[$at] ?? 0) + 1;
            }
        }

        foreach ($index['items'] as $at => $record) {
            $tier = self::exactTier($record, $query, $tokens);

            if ($tier < 0 && ($hits[$at] ?? 0) < $needed) {
                continue;
            }

            $found[] = [
                'id' => $record['id'],
                'title' => $record['title'],
                'score' => ($tier >= 0 ? 10 - $tier : 0) + ($score[$at] ?? 0) / count($tokens),
                'exact' => $tier >= 0,
            ];
        }

        usort($found, static fn (array $a, array $b): int => $b['score'] <=> $a['score'] ?: mb_strlen($a['title']) <=> mb_strlen($b['title']));

        return $found;
    }

    /**
     * Everything that matches, best first. A query typed on an English keyboard is read both ways
     * and the reading whose best hit scores higher wins, so "milwakee" stays English.
     *
     * @return list<array{id: string, title: string, score: float, exact: bool}>
     */
    public static function search(array $index, ?string $raw): array
    {
        $query = self::normalize($raw);

        if ($query === '') {
            return [];
        }

        $found = self::rank($index, $query);
        $typed = self::fromKeyboard($query);

        if ($typed !== '') {
            $hebrew = self::rank($index, $typed);

            if ($hebrew && (! $found || $hebrew[0]['score'] > $found[0]['score'])) {
                $found = $hebrew;
            }
        }

        return $found;
    }

    private static function allTokensStartWords(string $name, array $tokens): bool
    {
        foreach ($tokens as $token) {
            if (! str_contains(' '.$name, ' '.$token)) {
                return false;
            }
        }

        return true;
    }
}
