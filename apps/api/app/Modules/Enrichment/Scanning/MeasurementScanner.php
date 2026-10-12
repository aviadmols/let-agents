<?php

namespace App\Modules\Enrichment\Scanning;

/**
 * Finds every number-with-unit in product text, in code, so a model never has to type a
 * number. The model's job shrinks to saying which measurement is which spec ("m2 is the
 * torque"), which is cheaper and cannot invent a value.
 */
final class MeasurementScanner
{
    /** A number: "2,800", "1.5", "7-1/4", "7.1/4", "½7", "4½". */
    private const NUMBER = '(?<num>\d+[ .\-]\d{1,2}/(?:2|4|8|16|32)(?!\d)|\d{1,2}/(?:2|4|8|16|32)(?!\d)|[½¼¾]\d+|\d+[½¼¾]|\d{1,3}(?:,\d{3})+(?:\.\d+)?|\d+(?:[.,]\d+)?)';

    /**
     * Not glued to a letter or another number before it: "DTD153" and "V20" are model names.
     * After a slash only when the slash follows a letter: "2.5HP/1800W" yes, the 4 of "1/4" no.
     */
    private const BEFORE = '(?<![\p{L}\d.,½¼¾])(?<!\d/)';

    private const SPACE = '[ \x{00A0}\x{202F}]{0,2}';

    public function __construct(private readonly int $maxMeasurements = 30) {}

    /** @return list<Measurement> in order of first appearance, one per distinct dimension and value */
    public function scan(string $text): array
    {
        $text = TextNormalizer::clean($text);
        $found = [];

        foreach (Units::spellings() as $spelling) {
            foreach ($this->matches($text, $spelling) as $match) {
                $found[] = $match;
            }
        }

        usort($found, fn (array $a, array $b): int => [$a['start'], -$a['length']] <=> [$b['start'], -$b['length']]);

        $accepted = [];
        $lastEnd = -1;
        foreach ($found as $match) {
            // The single value inside "2X18V" is offered next to the product, not instead of it.
            if ($match['inner'] ?? false) {
                $accepted[] = $match;

                continue;
            }

            if ($match['start'] < $lastEnd) {
                continue;
            }
            $accepted[] = $match;
            $lastEnd = $match['start'] + $match['length'];
        }

        $measurements = [];
        $index = [];
        foreach ($accepted as $match) {
            $key = $match['dimension'].'|'.Measurement::compact($match['value']);

            if (isset($index[$key])) {
                $measurements[$index[$key]]['occurrences']++;

                continue;
            }

            if (count($measurements) >= $this->maxMeasurements) {
                continue;
            }

            $index[$key] = count($measurements);
            $measurements[] = $match + ['occurrences' => 1];
        }

        return array_values(array_map(fn (array $m, int $i): Measurement => new Measurement(
            id: 'm'.($i + 1),
            dimension: $m['dimension'],
            value: $m['value'],
            unit: Units::CANONICAL[$m['dimension']],
            raw: $m['raw'],
            quote: Snippet::around($text, $m['start'], $m['length']),
            occurrences: $m['occurrences'],
        ), $measurements, array_keys($measurements)));
    }

    /**
     * @param  array{dimension: string, factor: float, unit: string, mode: string}  $spelling
     * @return list<array{dimension: string, value: float, raw: string, start: int, length: int}>
     */
    private function matches(string $text, array $spelling): array
    {
        $number = self::NUMBER;
        $space = self::SPACE;

        $pattern = match ($spelling['mode']) {
            'multiply' => '~'.self::BEFORE.'(?<mult>[2-4])'.$space.'[xX×]'.$space.$number.$space.$spelling['unit'].'~u',
            // '"½7', '"7-1/2' and '”9 230': an inch mark before the number. A plain whole number
            // followed by Hebrew text is left alone: that is how a quoted phrase ('"10 מקדחים"') looks.
            'prefix' => '~(?<![\p{L}\d])'.$spelling['unit'].'(?<num>[½¼¾]\d+|\d+[½¼¾]|\d+[.\-]\d+/\d+|\d+(?:\.\d+)?(?!'.$space.'[\x{0590}-\x{05FF}]))(?![\d.,/\-])~u',
            default => '~'.self::BEFORE.$number.$space.$spelling['unit'].'~iu',
        };

        if (! preg_match_all($pattern, $text, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $results = [];
        foreach ($all as $match) {
            $number = NumberParser::parse($match['num'][0]);

            if ($number === null) {
                continue;
            }

            if ($spelling['mode'] === 'multiply') {
                // "2X18V" is 36 V on a tool that takes two batteries at once, and two 18 V
                // batteries in a list of what is in the box. Both are offered; the model decides.
                $results[] = [
                    'dimension' => $spelling['dimension'],
                    'value' => $number * $spelling['factor'],
                    'raw' => $match[0][0],
                    'start' => $match['num'][1],
                    'length' => strlen($match[0][0]) - ($match['num'][1] - $match[0][1]),
                    'inner' => true,
                ];

                $number *= (int) $match['mult'][0];
            }

            $value = $number * $spelling['factor'];

            if ($value <= 0) {
                continue;
            }

            $results[] = [
                'dimension' => $spelling['dimension'],
                'value' => $value,
                'raw' => $match[0][0],
                'start' => $match[0][1],
                'length' => strlen($match[0][0]),
            ];
        }

        return $results;
    }
}
