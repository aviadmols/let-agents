<?php

namespace App\Modules\Assistant\Support;

/** A shopper's question as code sees it: normalized to find the same question again, and checked for contact details. */
final class Question
{
    private const CONTACT = '~https?://|www\.|\S+@\S+\.\S+|(?:\+?972|\b0)[\s-]?\d{1,2}[\s-]?\d{3}[\s-]?\d{4}\b~iu';

    /** Lowercase, punctuation and repeated spaces removed: "האם זה מתאים לחוץ?" and "האם זה מתאים לחוץ" are one question. */
    public static function normalize(string $question): string
    {
        $text = mb_strtolower(trim($question));
        $text = (string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    public static function key(string $question): string
    {
        return hash('sha256', self::normalize($question));
    }

    /** Links, emails and phone numbers are never sent to a model or stored. */
    public static function hasContactDetails(string $question): bool
    {
        return preg_match(self::CONTACT, $question) === 1;
    }

    /** Words a question is made of that name nothing: asked, not about. */
    private const QUESTION_WORDS = ['מה', 'מהי', 'מהו', 'איך', 'האם', 'יש', 'אפשר', 'כמה', 'מתי', 'למה', 'של', 'את', 'על', 'עם', 'לי', 'שלכם', 'אצלכם', 'what', 'is', 'the', 'how', 'do', 'you', 'your', 'can', 'i'];

    /**
     * The words of a question that may name something: a plural as its singular (ביטולים finds
     * ביטול), and a word with its leading letter dropped as well.
     *
     * @return list<string>
     */
    public static function words(string $question): array
    {
        $words = [];

        foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($question)) ?: [] as $word) {
            if (mb_strlen($word) < 3 || in_array($word, self::QUESTION_WORDS, true)) {
                continue;
            }

            $base = mb_strlen($word) > 4 ? (string) preg_replace('/(ים|ות)$/u', '', $word) : $word;
            $words[] = $base;

            // "הביטולים" is "ביטול" with the letter in front: ה, ו, ב, ל, מ, ש, כ.
            if (mb_strlen($base) > 3 && preg_match('/^[הובלמשכ]/u', $base) === 1) {
                $words[] = mb_substr($base, 1);
            }
        }

        return array_values(array_unique($words));
    }

    /** Collapses whitespace and cuts to $max characters. */
    public static function clean(string $question, int $max): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $question)), 0, $max);
    }
}
