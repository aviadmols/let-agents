<?php

namespace App\Modules\Retrieval\Support;

/**
 * Cuts a document into pieces of at most a given length, at the natural joints: paragraphs
 * first, then sentences, then words. Every piece starts with the document's title, so a piece
 * from the middle of a long guide still says what it is about.
 *
 * Multibyte-safe throughout: Hebrew is measured in characters and split with /u patterns only.
 */
final class Chunker
{
    /** @return list<string> at least one piece for a document with any text */
    public static function split(string $title, string $text, int $maxChars): array
    {
        $title = self::clean($title);
        $text = self::clean($text, keepLines: true);
        $budget = max(100, $maxChars - mb_strlen($title) - 1);

        if ($text === '') {
            return $title === '' ? [] : [$title];
        }

        $pieces = [];
        $current = '';

        foreach (self::units($text, $budget) as $unit) {
            if ($current !== '' && mb_strlen($current) + 1 + mb_strlen($unit) > $budget) {
                $pieces[] = $current;
                $current = '';
            }

            $current = $current === '' ? $unit : $current."\n".$unit;
        }

        if ($current !== '') {
            $pieces[] = $current;
        }

        return array_map(fn (string $piece): string => $title === '' ? $piece : $title."\n".$piece, $pieces);
    }

    /**
     * Cut at the text's own joints (8b): a heading ("## …", as HtmlText writes one) starts a new piece, and every piece
     * begins with its path, the title and the headings above it ("מדיניות משלוחים > אספקה ומשלוחים"), so a piece read
     * alone says where it stands, for its vector and for the writer alike. Within a section, its paragraphs and list
     * items as split() packs them; never inside a table, and a sentence is cut only when it alone is longer than a piece.
     * A heading keeps its number as written ("3. מסירת מידע"), and a list item's number is never cut from its text.
     *
     * @return list<string> at least one piece for a document with any text
     */
    public static function sections(string $title, string $text, int $maxChars): array
    {
        $title = self::clean($title);
        $text = self::clean($text, keepLines: true);

        if ($text === '') {
            return $title === '' ? [] : [$title];
        }

        $sections = [];
        $path = [];
        $blocks = [];
        foreach (self::blocks($text) as $block) {
            if (preg_match('/^(#{1,6})\s+(.+)$/u', $block, $m) === 1) {
                if ($blocks !== []) {
                    $sections[] = [$path, $blocks];
                    $blocks = [];
                }
                $level = strlen($m[1]);
                $path = array_filter($path, fn (int $at): bool => $at < $level, ARRAY_FILTER_USE_KEY);
                $path[$level] = trim($m[2]);

                continue;
            }
            $blocks[] = $block;
        }
        if ($blocks !== []) {
            $sections[] = [$path, $blocks];
        }

        $plain = fn (string $name): string => mb_strtolower(trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $name)));
        $out = [];
        foreach ($sections as [$headings, $blocks]) {
            // the title and the headings under it, each once (a page whose first heading is its own title)
            $names = [];
            foreach ([$title, ...array_values($headings)] as $name) {
                if ($plain($name) !== '' && ! in_array($plain($name), array_map($plain, $names), true)) {
                    $names[] = $name;
                }
            }
            $head = implode(' > ', $names);
            $budget = max(100, $maxChars - mb_strlen($head) - 1);
            $current = '';
            foreach (self::unitsOf($blocks, $budget, true) as $unit) {
                if ($current !== '' && mb_strlen($current) + 1 + mb_strlen($unit) > $budget) {
                    $out[] = $head === '' ? $current : $head."\n".$current;
                    $current = '';
                }
                $current = $current === '' ? $unit : $current."\n".$unit;
            }
            if ($current !== '') {
                $out[] = $head === '' ? $current : $head."\n".$current;
            }
        }

        return $out === [] ? ($title === '' ? [] : [$title]) : $out;
    }

    /**
     * Paragraphs, or the sentences of a paragraph too long to keep whole, or the words of a
     * sentence too long to keep whole.
     *
     * @return list<string>
     */
    private static function units(string $text, int $budget): array
    {
        return self::unitsOf(self::blocks($text), $budget);
    }

    /**
     * @param  list<string>  $blocks
     * @return list<string>
     */
    private static function unitsOf(array $blocks, int $budget, bool $keepNumbers = false): array
    {
        $units = [];
        // a list item's number ("1.", "12.") is no sentence's end
        $sentences = $keepNumbers ? '/(?<=[.!?…:;])(?<!\s\d\.)(?<!^\d\.)(?<!\s\d\d\.)(?<!^\d\d\.)\s+/u' : '/(?<=[.!?…:;])\s+/u';

        foreach ($blocks as $paragraph) {
            if (str_starts_with($paragraph, '|') && mb_strlen($paragraph) > $budget) {
                array_push($units, ...self::tableParts($paragraph, $budget));

                continue;
            }

            if (mb_strlen($paragraph) <= $budget) {
                $units[] = $paragraph;

                continue;
            }

            foreach (preg_split($sentences, $paragraph) ?: [] as $sentence) {
                if (mb_strlen($sentence) <= $budget) {
                    $units[] = $sentence;

                    continue;
                }

                $line = '';

                foreach (preg_split('/\s+/u', $sentence) ?: [] as $word) {
                    if ($line !== '' && mb_strlen($line) + 1 + mb_strlen($word) > $budget) {
                        $units[] = $line;
                        $line = '';
                    }

                    $line = $line === '' ? mb_substr($word, 0, $budget) : $line.' '.$word;
                }

                if ($line !== '') {
                    $units[] = $line;
                }
            }
        }

        return $units;
    }

    /**
     * The text's paragraphs, a table's rows ("| a | b |", HtmlText) held together as one: a row read
     * away from its table's header loses what its values mean.
     *
     * @return list<string>
     */
    private static function blocks(string $text): array
    {
        $blocks = [];
        $table = [];

        foreach (preg_split('/\n+/u', $text) ?: [] as $line) {
            $line = trim($line);

            if (str_starts_with($line, '|')) {
                $table[] = $line;

                continue;
            }

            if ($table !== []) {
                $blocks[] = implode("\n", $table);
                $table = [];
            }

            if ($line !== '') {
                $blocks[] = $line;
            }
        }

        if ($table !== []) {
            $blocks[] = implode("\n", $table);
        }

        return $blocks;
    }

    /**
     * A table too long for one piece, cut between rows, each part starting with the header row again.
     *
     * @return list<string>
     */
    private static function tableParts(string $table, int $budget): array
    {
        $rows = explode("\n", $table);
        $head = isset($rows[1]) && preg_match('/^\|(?:\s*-{3,}\s*\|)+$/u', $rows[1]) === 1 ? array_splice($rows, 0, 2) : [];
        $prefix = $head === [] ? '' : implode("\n", $head)."\n";
        $room = max(50, $budget - mb_strlen($prefix));
        $parts = [];
        $current = '';

        foreach ($rows as $row) {
            $row = mb_substr($row, 0, $room);

            if ($current !== '' && mb_strlen($current) + 1 + mb_strlen($row) > $room) {
                $parts[] = $prefix.$current;
                $current = '';
            }

            $current = $current === '' ? $row : $current."\n".$row;
        }

        if ($current !== '') {
            $parts[] = $prefix.$current;
        }

        return $parts;
    }

    private static function clean(string $text, bool $keepLines = false): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/[^\S\n]+/u', ' ', $text);

        return trim($keepLines ? (string) preg_replace('/ *\n */u', "\n", $text) : (string) preg_replace('/\s+/u', ' ', $text));
    }
}
