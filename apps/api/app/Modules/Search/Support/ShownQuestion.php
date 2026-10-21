<?php

namespace App\Modules\Search\Support;

use App\Modules\Assistant\Models\AssistantAnswer;

/**
 * A shopper's question as other shoppers are shown it ("What people ask here", the box's saved answers): the wording
 * the first model mended when it had slips (kept on the answer), written cleanly (one question mark, no space before
 * it, no doubled spaces or marks), and only one that reads as a question someone would ask: not one word, not a
 * paragraph, no laughter, no emoji, no letter typed five times. Code only.
 */
final class ShownQuestion
{
    private const MIN_WORDS = 2;

    private const MAX_WORDS = 16;

    public static function text(AssistantAnswer $answer): string
    {
        $mended = (array) ($answer->flags ?? []);
        $text = is_string($mended['corrected'] ?? null) && trim($mended['corrected']) !== '' ? $mended['corrected'] : (string) $answer->question;

        return self::clean($text);
    }

    public static function clean(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($text)));
        // "??", "?!?", "..": one mark; none before it a space
        $text = (string) preg_replace('/([?؟!.,])\1+/u', '$1', $text);
        $text = (string) preg_replace('/[?؟][!?؟]+|[!][?؟]+/u', '?', $text);
        $text = (string) preg_replace('/\s+([?؟!.,:;])/u', '$1', $text);

        return trim($text, " \t-–—");
    }

    public static function fit(string $text): bool
    {
        $words = preg_split('/\s+/u', trim($text)) ?: [];

        return count($words) >= self::MIN_WORDS && count($words) <= self::MAX_WORDS
            // laughter, a letter held down, an emoji: not a question of the store
            && preg_match('/(?:חח|הה|ha|lo){2,}|(?<!\p{L})[חה]{3,}(?!\p{L})|(\p{L})\1{4,}|[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/iu', $text) !== 1;
    }
}
