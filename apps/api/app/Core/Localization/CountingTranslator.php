<?php

namespace App\Core\Localization;

use Illuminate\Translation\Translator;

/**
 * Laravel's translator, with a word that agrees with the number before it: "{n:search/searches}" reads
 * "search" when :n is 1 and "searches" otherwise, in a line that may count several things at once
 * (":steps {steps:action/actions} · :pages {pages:page/pages}"), where trans_choice takes one number.
 * The number is the replacement's own (an int, or text such as "1,129"); a block whose name has no
 * replacement, or no number, is left as it is written.
 */
class CountingTranslator extends Translator
{
    /** "{name:one/other}": a replacement's name, then the word for one and the word for any other number. */
    private const BLOCK = '/\{([A-Za-z_][A-Za-z0-9_]*):([^{}\/|]*)\/([^{}\/|]*)\}/u';

    public static function from(Translator $translator): self
    {
        $counting = new self($translator->getLoader(), $translator->getLocale());
        $counting->setFallback($translator->getFallback());

        return $counting;
    }

    /** "1,129" is 1129, "1" is 1; null for anything that is not a number. */
    public static function number(mixed $value): int|float|null
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if (! is_string($value) || preg_match('/^\s*-?[\d,\x{00A0}\x{202F} ]*(\.\d+)?\s*$/u', $value) !== 1 || preg_match('/\d/', $value) !== 1) {
            return null;
        }

        $plain = (string) preg_replace('/[^\d.\-]/', '', $value);

        return str_contains($plain, '.') ? (float) $plain : (int) $plain;
    }

    /** @param  array<string, mixed>  $replace */
    protected function makeReplacements($line, array $replace)
    {
        if (is_string($line) && $replace !== [] && str_contains($line, '{')) {
            $line = (string) preg_replace_callback(self::BLOCK, function (array $m) use ($replace): string {
                $n = array_key_exists($m[1], $replace) ? self::number($replace[$m[1]]) : null;

                return $n === null ? $m[0] : (abs($n) == 1 ? $m[2] : $m[3]);
            }, $line);
        }

        return parent::makeReplacements($line, $replace);
    }
}
