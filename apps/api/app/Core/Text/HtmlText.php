<?php

namespace App\Core\Text;

/**
 * A store's HTML (a product description, a post) as text that keeps its structure, the way a reader
 * and a model need it: headings as "## ", list items as "- ", and a table as rows of "| a | b |" with
 * its header row first, so "9 מ״מ | חיפוי קל" stays one row and the values never mix. Markup, scripts
 * and entities go; paragraphs and line breaks stay. The WordPress plugin's PlainText does the same.
 *
 * Multibyte-safe: /u patterns only, no trim() with a character list.
 */
final class HtmlText
{
    /** A table cell longer than this is cut: a table is for short values. */
    private const CELL = 200;

    public static function from(?string $html): string
    {
        $text = (string) $html;

        if ($text === '') {
            return '';
        }

        $text = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1>#isu', ' ', $text);
        $text = (string) preg_replace_callback('#<table\b[^>]*>(.*?)</table\s*>#isu', fn (array $m): string => "\n".self::table($m[1])."\n", $text);
        $text = (string) preg_replace_callback('#<h([1-6])\b[^>]*>#iu', fn (array $m): string => "\n".str_repeat('#', min(3, max(2, (int) $m[1]))).' ', $text);
        $text = self::lists($text);
        // Breaks with attributes (<br data-start="12">) and accordion titles (<summary>) end a line too.
        $text = (string) preg_replace('#<br\b[^>]*>|</(p|div|h[1-6]|tr|blockquote|summary|details|section|article|header|footer|figcaption|dt|dd|ul|ol)\s*>#iu', "\n", $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $text);
        $text = (string) preg_replace("/ *\n */u", "\n", $text);
        // A list item or heading left empty by its markup is no line.
        $text = (string) preg_replace("/^(?:-|#{2,3})[ \t]*$/mu", '', $text);
        $text = (string) preg_replace("/\n{3,}/u", "\n\n", $text);

        return (string) preg_replace('/^\s+|\s+$/u', '', $text);
    }

    /**
     * List items as lines: a bulleted list's as "- ", a numbered list's by their place in it, nested ones under their
     * parent's number ("7.", "7.1.", "7.2."), as the page shows them: a page's own sections and their cross-references
     * ("כאמור בסעיף 7.2") hold only so. An item that writes its own number of more than one level ("3.1.") keeps it; one
     * that writes a single number the list contradicts (every item "1.", Miss Nori's terms, 17.11) is given its place.
     */
    private static function lists(string $html): string
    {
        $parts = preg_split('#(</?(?:ol|ul|li)\b[^>]*>)#iu', $html, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$html];
        $read = fn (string $part): ?array => preg_match('#^<(/?)(ol|ul|li)\b([^>]*)>$#iu', $part, $m) === 1 ? [$m[1] === '/', strtolower($m[2]), $m[3]] : null;

        // A numbered list read as numbers only when its numbers carry something: a list inside it, or items that write
        // their own. A plain one stays "- " as it always was, so no product's text changes for nothing.
        $numbered = [];
        $depth = 0;
        $list = -1;
        $afterItem = false;
        foreach ($parts as $i => $part) {
            if (($t = $read($part)) !== null) {
                [$close, $name] = $t;
                if ($name === 'ol' && ! $close) {
                    $list = $depth === 0 ? $i : $list;
                    if ($depth > 0) {
                        $numbered[$list] = true;
                    }
                    $depth++;
                } elseif ($name === 'ol') {
                    $depth = max(0, $depth - 1);
                }
                $afterItem = $name === 'li' && ! $close && $depth > 0;

                continue;
            }
            if ($afterItem && trim(strip_tags($part)) !== '') {
                if (preg_match('/^\s*(?:<[^>]+>\s*)*\d+(?:\.\d+)*\.\s/u', $part) === 1) {
                    $numbered[$list] = true;
                }
                $afterItem = false;
            }
        }

        $stack = [];
        $out = '';
        $pending = null;
        $list = -1;

        foreach ($parts as $i => $part) {
            if ($read($part) !== null && $read($part)[1] === 'ol' && ! $read($part)[0] && array_filter($stack, fn (array $level): bool => $level[0] === 'ol') === []) {
                $list = $i;
            }
            if ($read($part) !== null && $read($part)[1] === 'li' && ! $read($part)[0] && ! isset($numbered[$list])
                && ($top = array_key_last($stack)) !== null && $stack[$top][0] === 'ol') {
                $out .= "\n- ";

                continue;
            }
            if (preg_match('#^<(/?)(ol|ul|li)\b([^>]*)>$#iu', $part, $m) === 1) {
                [$close, $tag, $attributes] = [$m[1] === '/', strtolower($m[2]), $m[3]];
                if ($tag !== 'li') {
                    // kept for the lines a list's end makes, below
                    $out .= $part;
                    if ($close) {
                        array_pop($stack);
                    } else {
                        $start = $tag === 'ol' && preg_match('/\bstart\s*=\s*["\']?(\d+)/iu', $attributes, $s) === 1 ? (int) $s[1] - 1 : 0;
                        $stack[] = [$tag, $start];
                    }

                    continue;
                }
                if ($close) {
                    continue;
                }
                $top = array_key_last($stack);
                if ($top === null || $stack[$top][0] === 'ul') {
                    $out .= "\n- ";

                    continue;
                }
                $stack[$top][1]++;
                $pending = implode('.', array_map(fn (array $level): int => $level[1], array_filter($stack, fn (array $level): bool => $level[0] === 'ol'))).'.';

                continue;
            }
            // the item's own text: its number is written once, before it
            if ($pending !== null && trim(strip_tags($part)) !== '') {
                $plain = ltrim($part);
                if (preg_match('/^((?:<[^>]+>\s*)*)(\d+(?:\.\d+)+)\.?\s+/u', $plain, $own) === 1) {
                    $out .= "\n".$part;
                } else {
                    $out .= "\n".$pending.' '.(string) preg_replace('/^((?:<[^>]+>\s*)*)\d+\.\s+/u', '$1', $plain);
                }
                $pending = null;

                continue;
            }
            $out .= $part;
        }

        return $out;
    }

    /** One table's rows as "| a | b |", the header row first and marked; an empty table is nothing. */
    private static function table(string $inner): string
    {
        // A row is what stands between row tags, so one whose <tr> or </tr> the editor dropped is still a row of its
        // own (Gueta's shipping table: "</tr><td>הובלה…</td>…</tr>"), never lost or run into the next.
        $rows = preg_split('#<tr\b[^>]*>|</tr\s*>#iu', $inner) ?: [$inner];
        $lines = [];
        $header = false;
        $i = 0;

        foreach ($rows as $row) {
            preg_match_all('#<(t[hd])\b[^>]*>(.*?)</t[hd]\s*>#isu', $row, $cells, PREG_SET_ORDER);
            $values = array_map(fn (array $c): string => self::cell($c[2]), $cells);

            if ($values === [] || implode('', $values) === '') {
                if ($values !== []) {
                    $i++;
                }

                continue;
            }

            $lines[] = '| '.implode(' | ', $values).' |';

            // The first row is the header when it is all <th>: the line under it says so.
            if ($i++ === 0 && count($cells) === count(array_filter($cells, fn (array $c): bool => strtolower($c[1]) === 'th'))) {
                $lines[] = '|'.str_repeat(' --- |', count($values));
                $header = true;
            }
        }

        // A table that never says its header: its first row is read as one.
        if (! $header && count($lines) > 1) {
            array_splice($lines, 1, 0, ['|'.str_repeat(' --- |', substr_count($lines[0], ' | ') + 1)]);
        }

        return implode("\n", $lines);
    }

    private static function cell(string $html): string
    {
        $text = html_entity_decode(strip_tags((string) preg_replace('#<br\b[^>]*>#iu', ' ', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/[\s\x{00A0}]+/u', ' ', $text);
        $text = str_replace('|', '/', (string) preg_replace('/^\s+|\s+$/u', '', $text));

        return mb_strlen($text) > self::CELL ? mb_substr($text, 0, self::CELL).'…' : $text;
    }
}
