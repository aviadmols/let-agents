<?php

namespace App\Modules\Enrichment\Scanning;

/**
 * Shortens product text before a model sees it, in code: drops "click here" lines, lines that
 * repeat the title or each other, and, past the budget, the least informative lines first.
 * Lines with numbers or "label: value" pairs are kept before prose.
 */
final class TextCondenser
{
    private const BOILERPLATE = '~לחץ כאן|לחצו כאן|למעבר לאתר|לרכישת מוצרי|לרשימת כל|לכל המוצרים|click here|read more|קרא עוד|קראו עוד|שתפו את|share this~iu';

    /**
     * @param  list<string>  $sections  title first, then the rest in order of trust
     * @param  list<string>  $boilerplate  normalized lines repeated across the catalog (see Boilerplate)
     * @param  bool  $headingsWithContent  past the budget, keep a heading ("שימושים:") only with the line after it,
     *                                     so short headings do not take the place of what they introduce
     * @return array{text: string, truncated: bool, source_chars: int}
     */
    public function condense(array $sections, int $maxChars, array $boilerplate = [], bool $headingsWithContent = false): array
    {
        $lines = [];
        $seen = [];
        $drop = array_fill_keys($boilerplate, true);
        $sourceChars = 0;

        foreach ($sections as $section) {
            $section = TextNormalizer::clean($section);
            $sourceChars += mb_strlen($section);

            foreach (explode("\n", $section) as $line) {
                $line = self::trimBullets($line);

                if ($line === '' || preg_match(self::BOILERPLATE, $line)) {
                    continue;
                }

                $key = TextNormalizer::forMatching($line);

                // A table's "| --- |" line is the same in every table and belongs to each.
                if (self::isRule($line)) {
                    $lines[] = $line;

                    continue;
                }

                if (isset($seen[$key]) || isset($drop[$key]) || $this->containedInEarlier($key, $seen)) {
                    continue;
                }

                $seen[$key] = true;
                $lines[] = $line;
            }
        }

        $full = implode("\n", $lines);

        if (mb_strlen($full) <= $maxChars) {
            return ['text' => $full, 'truncated' => false, 'source_chars' => $sourceChars];
        }

        // Over budget: the title, then informative lines, then prose, each in original order.
        $keep = [0 => true];
        $used = mb_strlen($lines[0] ?? '');

        $heads = self::tableHeads($lines);

        foreach ([true, false] as $informative) {
            foreach ($lines as $i => $line) {
                if (isset($keep[$i]) || $this->isInformative($line) !== $informative || ($headingsWithContent && self::isHeading($line))) {
                    continue;
                }

                // A table row goes with its table's header row and rule, or not at all: "| 9 | חיפוי |"
                // means nothing without "| עובי | שימוש |".
                $with = array_values(array_filter($heads[$i] ?? [], fn (int $h): bool => ! isset($keep[$h])));
                $length = mb_strlen($line) + 1 + array_sum(array_map(fn (int $h): int => mb_strlen($lines[$h]) + 1, $with));

                if ($used + $length > $maxChars) {
                    continue;
                }

                foreach ([...$with, $i] as $k) {
                    $keep[$k] = true;
                }
                $used += $length;
            }
        }

        if ($headingsWithContent) {
            foreach ($lines as $i => $line) {
                if (! isset($keep[$i]) && self::isHeading($line) && isset($keep[$i + 1]) && $used + mb_strlen($line) + 1 <= $maxChars) {
                    $keep[$i] = true;
                    $used += mb_strlen($line) + 1;
                }
            }
        }

        ksort($keep);

        return [
            'text' => implode("\n", array_map(fn (int $i): string => $lines[$i], array_keys($keep))),
            'truncated' => true,
            'source_chars' => $sourceChars,
        ];
    }

    /** @return list<string> the normalized lines of a section, the same way condense() sees them */
    public static function lineKeys(string $section): array
    {
        $keys = [];

        foreach (explode("\n", TextNormalizer::clean($section)) as $line) {
            $line = self::trimBullets($line);

            if ($line !== '') {
                $keys[] = TextNormalizer::forMatching($line);
            }
        }

        return $keys;
    }

    /**
     * Removes bullets and spaces at both ends. Not trim(): its character list is bytes, and the
     * bytes of "•" also occur inside Hebrew letters, which it would cut in half.
     */
    private static function trimBullets(string $line): string
    {
        return (string) preg_replace('/^[\s•·\-*]+|[\s•·\-*]+$/u', '', $line);
    }

    /** A short line that only introduces what follows: "יתרונות המוצר:", "## מפרט טכני". */
    private static function isHeading(string $line): bool
    {
        return mb_strlen($line) <= 60 && (bool) preg_match('~:\s*$|^#{2,3}\s~u', $line);
    }

    /** A table row ("| 9 מ״מ | חיפוי קל |") is a detail, as a "label: value" line is. */
    private function isInformative(string $line): bool
    {
        return (str_starts_with($line, '|') || (bool) preg_match('~\d|:\s*\S~u', $line)) && mb_strlen($line) <= 200;
    }

    private static function isRule(string $line): bool
    {
        return preg_match('/^\|(?:\s*-{3,}\s*\|)+$/u', $line) === 1;
    }

    /**
     * For each table row past a table's header, the header row and rule it needs (HtmlText writes a
     * table as "| a | b |" lines, the header row first and a "| --- |" rule under it).
     *
     * @param  list<string>  $lines
     * @return array<int, list<int>>
     */
    private static function tableHeads(array $lines): array
    {
        $heads = [];
        $current = null;

        foreach ($lines as $i => $line) {
            if (! str_starts_with($line, '|')) {
                $current = null;

                continue;
            }

            if ($current === null) {
                $current = isset($lines[$i + 1]) && self::isRule($lines[$i + 1]) ? [$i, $i + 1] : [];

                continue;
            }

            if (! in_array($i, $current, true)) {
                $heads[$i] = $current;
            }
        }

        return $heads;
    }

    /** A short line already said inside a longer earlier one, such as a title repeated in a sentence. */
    private function containedInEarlier(string $key, array $seen): bool
    {
        if (mb_strlen($key) > 120) {
            return false;
        }

        foreach (array_keys($seen) as $earlier) {
            if (mb_strlen((string) $earlier) > mb_strlen($key) && str_contains((string) $earlier, $key)) {
                return true;
            }
        }

        return false;
    }
}
