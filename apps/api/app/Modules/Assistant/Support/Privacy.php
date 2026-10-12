<?php

namespace App\Modules\Assistant\Support;

/**
 * Personal details in a shopper's question: found, and replaced before anything is stored, logged
 * or sent to the nightly review. Phone numbers, emails, links, payment card numbers (Luhn), Israeli
 * ID numbers (their check digit), and street addresses where the words say so. A SKU or a model
 * number is none of these: cards and IDs must pass their checksums.
 */
final class Privacy
{
    private const EMAIL = '/[^\s@<>()]+@[^\s@<>()]+\.[a-z]{2,}/iu';

    private const LINK = '~\bhttps?://\S+|\bwww\.\S+~iu';

    /** Israeli mobiles and landlines, and international numbers with a +. */
    private const PHONE = '/(?<![\d])(?:\+\d{1,3}[\s.-]?)?(?:\(?0\d{1,2}\)?[\s.-]?)?\d{3}[\s.-]?\d{4}(?![\d])/u';

    /** 13 to 19 digits, maybe in groups of four. */
    private const CARD = '/(?<![\d])(?:\d[\s-]?){12,18}\d(?![\d])/u';

    /** Nine digits, maybe as 8 and a check digit. */
    private const ID = '/(?<![\d])\d{8}[\s-]?\d(?![\d])/u';

    /** A street with its number: "רחוב הרצל 12", "רח' בן יהודה 4", "שדרות רוטשילד 10", "12 Main St". */
    private const ADDRESS = '/(?:רחוב|רח[\'׳]|שד[\'׳]|שדרות|דרך|סמטת|כיכר)\s+[\p{Hebrew}\s"\'׳״-]{2,30}?\s\d{1,4}\b|\b\d{1,5}\s+[A-Z][a-z]+(?:\s[A-Z][a-z]+)?\s(?:St|Street|Ave|Avenue|Rd|Road|Blvd)\b\.?/u';

    /**
     * @return array{text: string, found: list<string>} the text with each detail replaced by its kind, and the kinds found
     */
    public static function redact(string $text): array
    {
        $found = [];
        $replace = function (string $pattern, string $kind, ?callable $accept = null) use (&$text, &$found): void {
            $text = (string) preg_replace_callback($pattern, function (array $m) use ($kind, $accept, &$found): string {
                if ($accept !== null && ! $accept($m[0])) {
                    return $m[0];
                }
                $found[] = $kind;

                return '['.$kind.']';
            }, $text);
        };

        // Most particular first, so a card is not read as two phone numbers.
        $replace(self::EMAIL, 'email');
        $replace(self::LINK, 'link');
        $replace(self::CARD, 'card', fn (string $s): bool => self::luhn((string) preg_replace('/\D/', '', $s)));
        $replace(self::ID, 'id', fn (string $s): bool => self::israeliId((string) preg_replace('/\D/', '', $s)));
        $replace(self::PHONE, 'phone', fn (string $s): bool => strlen((string) preg_replace('/\D/', '', $s)) >= 9);
        $replace(self::ADDRESS, 'address');

        return ['text' => $text, 'found' => array_values(array_unique($found))];
    }

    public static function has(string $text): bool
    {
        return self::redact($text)['found'] !== [];
    }

    private static function luhn(string $digits): bool
    {
        if (strlen($digits) < 13 || strlen($digits) > 19 || preg_match('/^(\d)\1+$/', $digits) === 1) {
            return false;
        }

        $sum = 0;
        foreach (array_reverse(str_split($digits)) as $i => $d) {
            $n = (int) $d * ($i % 2 === 1 ? 2 : 1);
            $sum += $n > 9 ? $n - 9 : $n;
        }

        return $sum % 10 === 0;
    }

    private static function israeliId(string $digits): bool
    {
        if (strlen($digits) !== 9 || preg_match('/^(\d)\1+$/', $digits) === 1) {
            return false;
        }

        $sum = 0;
        foreach (str_split($digits) as $i => $d) {
            $n = (int) $d * ($i % 2 === 0 ? 1 : 2);
            $sum += $n > 9 ? $n - 9 : $n;
        }

        return $sum % 10 === 0;
    }
}
