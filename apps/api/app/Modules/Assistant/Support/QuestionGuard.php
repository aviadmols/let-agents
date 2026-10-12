<?php

namespace App\Modules\Assistant\Support;

/**
 * Code's first look at a shopper's question, before the saved answers are looked up or written and
 * before any model: what must never reach a model or the shared answers, read from
 * resources/guard.json.
 *
 * - refuse, counted toward blocking the visitor: an attempt to give the assistant instructions or
 *   pull out its rules (injection), page markup or a script (markup), a flood of one character.
 * - refuse, not counted: personal details (a phone, an email, a card, an ID, an address). The
 *   shopper made a mistake, not an attack; the details are never stored.
 * - support: a shopper who may hurt themselves gets care and where to find help, in their
 *   language, and is never counted.
 * - allow, with signals: words of a sensitive topic only tell the topic check what to weigh. A
 *   sensitive subject is not a reason to refuse: the store may sell exactly that.
 *
 * Code-like text is allowed: a SKU, a model number or a technical string is a fair question.
 */
final class QuestionGuard
{
    public const ALLOW = 'allow';

    public const REFUSE = 'refuse';

    public const SUPPORT = 'support';

    private const FINALS = ['ך' => 'כ', 'ם' => 'מ', 'ן' => 'נ', 'ף' => 'פ', 'ץ' => 'צ'];

    /** One character this many times in a row is a flood, not a word. */
    private const FLOOD_RUN = 12;

    /**
     * @return array{action: string, reason: string|null, counts: bool, personal: list<string>, signals: list<string>, redacted: string}
     */
    public static function inspect(string $question): array
    {
        $config = self::config();
        $text = strtr(mb_strtolower($question), self::FINALS);
        $privacy = Privacy::redact($question);
        $verdict = ['action' => self::ALLOW, 'reason' => null, 'counts' => false, 'personal' => $privacy['found'], 'signals' => [], 'redacted' => $privacy['text']];

        foreach ((array) $config['signals'] as $topic => $words) {
            if (self::matches($text, (array) $words)) {
                $verdict['signals'][] = (string) $topic;
            }
        }

        return match (true) {
            self::matches($text, (array) $config['support']) => ['action' => self::SUPPORT, 'reason' => 'support'] + $verdict,
            self::matches($text, (array) $config['injection']) => ['action' => self::REFUSE, 'reason' => 'injection', 'counts' => true] + $verdict,
            self::matches($text, (array) $config['markup']) => ['action' => self::REFUSE, 'reason' => 'markup', 'counts' => true] + $verdict,
            self::flooded($question) => ['action' => self::REFUSE, 'reason' => 'flood', 'counts' => true] + $verdict,
            // A link is no one's personal detail (a shopper pastes a product's): kept out of the log only.
            array_diff($privacy['found'], ['link']) !== [] => ['action' => self::REFUSE, 'reason' => 'personal_data'] + $verdict,
            default => $verdict,
        };
    }

    /** Care and where to find help, in the shopper's language; English for any other. */
    public static function supportText(string $locale): string
    {
        $texts = (array) self::config()['support_text'];

        return (string) ($texts[$locale] ?? $texts['en']);
    }

    private static function flooded(string $question): bool
    {
        if (preg_match('/(.)\1{'.(self::FLOOD_RUN - 1).',}/u', $question) === 1) {
            return true;
        }

        // Mostly symbols and pictures: fewer than a third letters or digits, past a short length.
        $chars = mb_strlen($question);
        $plain = (int) preg_match_all('/[\p{L}\p{N}\s]/u', $question);

        return $chars > 20 && $plain / $chars < 0.34;
    }

    /** @param list<string> $patterns */
    private static function matches(string $text, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (@preg_match('~'.strtr($pattern, self::FINALS).'~iu', $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private static function config(): array
    {
        static $config = null;

        return $config ??= (array) json_decode((string) file_get_contents(dirname(__DIR__).'/resources/guard.json'), true);
    }
}
