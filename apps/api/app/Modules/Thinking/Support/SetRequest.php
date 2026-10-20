<?php

namespace App\Modules\Thinking\Support;

/**
 * Whether a question asks to see a whole set of products, and what: "תציג לי את ספריית שיבולת?"
 * asks for "ספריית שיבולת", "תן לי את כל ספרי החטופים" for "ספרי החטופים", "איזה ספר מדע בדיוני יש
 * לכם?" for "ספר מדע בדיוני". It asks when it says "show" or "all", or asks which ones the store has;
 * not when it asks how, why, how much or which is best ("הכי"), which the assistant answers as it
 * does. Code only.
 */
final class SetRequest
{
    /** Words that ask to be shown. */
    private const SHOW = ['תציג', 'תציגי', 'תציגו', 'הצג', 'הציגו', 'תראה', 'תראי', 'תראו', 'הראה', 'הראו', 'תפרט', 'רשימת', 'show', 'list'];

    /** Words that ask for all of something. */
    private const ALL = ['כל', 'all', 'every'];

    /** Words that open a question of which ones: "איזה ספרי מדע בדיוני יש לכם?". */
    private const WHICH = ['איזה', 'איזו', 'אילו', 'which', 'what'];

    /** Words that ask what the store has, after WHICH: "יש לכם", "do you have", "מוכרים". */
    private const HAVE = '/(?<![\p{L}])(?:יש\s+(?:לכם|לך|אצלכם|באתר|בחנות)|מוכרים|(?:do|does)\s+(?:you|the\s+store)\s+(?:have|sell|carry)|you\s+(?:have|sell|carry))(?![\p{L}])/u';

    /** Words that ask something other than to see a set. */
    private const OTHER = ['איך', 'למה', 'מדוע', 'האם', 'כמה', 'מתי', 'הכי', 'ההבדל', 'how', 'why', 'when', 'whether', 'cheapest', 'best', 'most'];

    /** Words that only wrap the request. */
    private const WRAP = ['תן', 'תני', 'תנו', 'לי', 'לנו', 'את', 'כל', 'בבקשה', 'אפשר', 'יש', 'לכם', 'לך', 'אצלכם', 'שלכם', 'שיש', 'מה', 'אילו', 'איזה', 'איזו', 'רשימה', 'עכשיו', 'באתר', 'בחנות', 'מוכרים',
        'give', 'me', 'us', 'all', 'the', 'please', 'what', 'which', 'do', 'does', 'you', 'have', 'sell', 'carry', 'store', 'every', 'can', 'could', 'your'];

    /** A subject longer than this is a description, not a set. */
    private const MAX_WORDS = 6;

    public static function subject(string $question): ?string
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(trim($question))) ?: [];
        $words = array_values(array_filter($words, fn (string $w): bool => $w !== ''));
        // the words around the subject as they are read: a letter typed twice once ("איזזה" is "איזה")
        $plain = array_map(self::once(...), $words);

        $asks = array_intersect($plain, self::SHOW) !== [] || array_intersect($plain, self::ALL) !== []
            || (in_array($plain[0] ?? '', self::WHICH, true) && preg_match(self::HAVE, self::once(mb_strtolower($question))) === 1);

        if (! $asks || array_intersect($plain, self::OTHER) !== []) {
            return null;
        }

        $subject = array_values(array_filter($words, fn (string $w, int $i): bool => ! in_array($plain[$i], self::WRAP, true) && ! in_array($plain[$i], self::SHOW, true), ARRAY_FILTER_USE_BOTH));

        return $subject === [] || count($subject) > self::MAX_WORDS ? null : implode(' ', $subject);
    }

    /**
     * The subject as it is kept: the core of each word, sorted, so the same words in another order or
     * with the article are the same set ("ספרי החטופים", "ספרי חטופים").
     */
    public static function key(string $subject): string
    {
        $words = array_map(Words::core(...), Words::of($subject));
        sort($words, SORT_STRING);

        return mb_substr(implode(' ', $words), 0, 191);
    }

    /** A word with a Hebrew letter typed twice in a row typed once (English doubles its letters: "all"). */
    private static function once(string $text): string
    {
        return (string) preg_replace('/(\p{Hebrew})\1+/u', '$1', $text);
    }
}
