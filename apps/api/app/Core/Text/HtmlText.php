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
        $text = (string) preg_replace('#<li\b[^>]*>#iu', "\n- ", $text);
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

    /** One table's rows as "| a | b |", the header row first and marked; an empty table is nothing. */
    private static function table(string $inner): string
    {
        preg_match_all('#<tr\b[^>]*>(.*?)</tr\s*>#isu', $inner, $rows);
        $lines = [];
        $header = false;

        foreach ($rows[1] as $i => $row) {
            preg_match_all('#<(t[hd])\b[^>]*>(.*?)</t[hd]\s*>#isu', $row, $cells, PREG_SET_ORDER);
            $values = array_map(fn (array $c): string => self::cell($c[2]), $cells);

            if ($values === [] || implode('', $values) === '') {
                continue;
            }

            $lines[] = '| '.implode(' | ', $values).' |';

            // The first row is the header when it is all <th>: the line under it says so.
            if ($i === 0 && count($cells) === count(array_filter($cells, fn (array $c): bool => strtolower($c[1]) === 'th'))) {
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
