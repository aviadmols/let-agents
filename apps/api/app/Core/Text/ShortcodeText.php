<?php

namespace App\Core\Text;

/**
 * A WordPress page's text as the store's plugin sends it may still hold its page builder's shortcodes, when the
 * builder registers them only on the storefront: "[vc_row][vc_column width="2/3"]…", and a raw HTML block as base64
 * of URL-encoded HTML ("[vc_raw_html]JTNDdGFibGU…"), so a shipping table's prices are on the page for a reader yet
 * nowhere in its text, and the code that checks an answer's prices against the pages refuses true ones (Gueta's
 * shipping, 18.11). Here the raw block is decoded and read as HtmlText reads HTML, its table as rows; a builder's
 * own tags go, a `title` they carry stays as a line; the words between them stay as they were. Text without such
 * tags comes back unchanged, byte for byte.
 *
 * Multibyte-safe: /u patterns only.
 */
final class ShortcodeText
{
    /** A shortcode tag whose name is a builder's, joined by "_" or "-" (vc_row, et_pb_text, su_box, contact-form-7). */
    private const TAG = '~\[/?[a-z][a-z0-9]*(?:[_-][a-z0-9]+)+(?=[\s\]/])[^\[\]]*\]~u';

    /** WPBakery's raw HTML: base64 of the URL-encoded HTML, until its closing tag or the end of a cut text. */
    private const RAW_HTML = '~\[vc_raw_html\b[^\]]*\]\s*([A-Za-z0-9+/=\s]*)(?:\[/vc_raw_html\]|$)~u';

    /** Script blocks are never text. */
    private const RAW_JS = '~\[vc_raw_js\b[^\]]*\].*?(?:\[/vc_raw_js\]|$)~su';

    public static function readable(?string $text): ?string
    {
        if ($text === null || ! str_contains($text, '[')) {
            return $text;
        }

        $out = (string) preg_replace_callback(self::RAW_HTML, fn (array $m): string => "\n".self::rawHtml($m[1])."\n", $text);
        $out = (string) preg_replace(self::RAW_JS, ' ', $out);
        $out = (string) preg_replace_callback(self::TAG, fn (array $m): string => preg_match('/\btitle="([^"]+)"/u', $m[0], $t) === 1 ? "\n".$t[1]."\n" : ' ', $out);

        if ($out === $text) {
            return $text;
        }

        $out = (string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $out);
        $out = (string) preg_replace("/ *\n */u", "\n", $out);
        $out = (string) preg_replace("/\n{3,}/u", "\n\n", $out);

        return (string) preg_replace('/^\s+|\s+$/u', '', $out);
    }

    /** The HTML a raw block holds, as text; nothing when it is no base64 of UTF-8 HTML. */
    private static function rawHtml(string $encoded): string
    {
        $encoded = (string) preg_replace('/\s+/', '', $encoded);
        // a text cut inside the block: only whole groups of four decode
        $encoded = substr($encoded, 0, strlen($encoded) - strlen($encoded) % 4);
        $raw = base64_decode($encoded, true);
        if ($raw === false || $raw === '') {
            return '';
        }

        // an escape cut at the end ("%D7%9") is no character
        $html = rawurldecode((string) preg_replace('/%[0-9A-Fa-f]?$/', '', $raw));
        // and a letter cut between its bytes neither
        if (! mb_check_encoding($html, 'UTF-8')) {
            $html = (string) preg_replace('/[\xC0-\xFF][\x80-\xBF]*$/', '', $html);
        }

        return mb_check_encoding($html, 'UTF-8') ? HtmlText::from($html) : '';
    }
}
