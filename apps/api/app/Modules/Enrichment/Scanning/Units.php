<?php

namespace App\Modules\Enrichment\Scanning;

/**
 * The units the scanner recognizes, in Hebrew and English, grouped by dimension. Every
 * dimension has one canonical unit; each spelling carries its factor to it.
 *
 * Adding a unit is adding a line here and a test with a real product sentence.
 */
final class Units
{
    /** @var array<string, string> dimension => canonical unit */
    public const CANONICAL = [
        'voltage' => 'V',
        'power' => 'W',
        'charge' => 'Ah',
        'current' => 'A',
        'length' => 'mm',
        'speed' => 'rpm',
        'rate' => 'bpm',
        'torque' => 'Nm',
        'mass' => 'kg',
        'energy' => 'J',
        'pressure' => 'bar',
        'volume' => 'L',
        'count' => 'pcs',
        // A gemstone's weight. Gold's purity ("14 קארט", "18K") is a choice in the vocabulary, not a unit.
        'carat' => 'ct',
    ];

    /** A double quote mark in any of its forms, including two apostrophes ("מ''מ"). */
    private const Q = "(?:[\"״”“″]|['׳’]{2})";

    /** One apostrophe, not the first half of a double one. */
    private const APOS = "['׳’](?!['׳’])";

    /** Ends a Latin unit: not followed by another letter or digit ("18V" yes, "V20" no). */
    private const END = '(?![\p{L}\d])';

    /**
     * Modes: "suffix" is a number then a unit. "prefix" is an inch mark then a number, which is
     * how right-to-left text shows '7½"' ('"½7'). "multiply" reads "2X18V" as 36 V.
     *
     * @return list<array{dimension: string, factor: float, unit: string, mode: string}>
     */
    public static function spellings(): array
    {
        $q = self::Q;
        $a = self::APOS;
        $end = self::END;

        return [
            ['dimension' => 'voltage', 'factor' => 1.0, 'unit' => "(?:V{$end}|וולט)", 'mode' => 'multiply'],
            ['dimension' => 'voltage', 'factor' => 1.0, 'unit' => "(?:V{$end}|וולט|volt{$end})", 'mode' => 'suffix'],
            ['dimension' => 'power', 'factor' => 1000.0, 'unit' => "(?:kW{$end}|קילוואט|קילו\s?וואט)", 'mode' => 'suffix'],
            ['dimension' => 'power', 'factor' => 1.0, 'unit' => "(?:W{$end}|וואט|watt{$end})", 'mode' => 'suffix'],
            ['dimension' => 'charge', 'factor' => 0.001, 'unit' => "mAh{$end}", 'mode' => 'suffix'],
            ['dimension' => 'charge', 'factor' => 1.0, 'unit' => "(?:Ah{$end}|אמפר(?:\s?שעה)?|אמפ{$a})", 'mode' => 'suffix'],
            ['dimension' => 'current', 'factor' => 1.0, 'unit' => "A{$end}", 'mode' => 'suffix'],
            ['dimension' => 'length', 'factor' => 1.0, 'unit' => "(?:mm{$end}|מ{$q}מ|ממ(?![\p{L}])|מילימטר)", 'mode' => 'suffix'],
            ['dimension' => 'length', 'factor' => 10.0, 'unit' => "(?:cm{$end}|ס{$q}מ|סנטימטר)", 'mode' => 'suffix'],
            ['dimension' => 'length', 'factor' => 1000.0, 'unit' => "(?:m{$end}|מטר(?:ים)?(?![\p{L}])|מ{$a}(?![\p{L}\"״”“]))", 'mode' => 'suffix'],
            ['dimension' => 'length', 'factor' => 25.4, 'unit' => "(?:{$q}|אינץ{$a}?|inch(?:es)?{$end})", 'mode' => 'suffix'],
            ['dimension' => 'length', 'factor' => 25.4, 'unit' => $q, 'mode' => 'prefix'],
            ['dimension' => 'speed', 'factor' => 1.0, 'unit' => "(?:סל{$q}ד|rpm{$end}|סיבובים\s?(?:ל|ב)דקה)", 'mode' => 'suffix'],
            ['dimension' => 'rate', 'factor' => 1.0, 'unit' => "(?:(?:פעימות|מכות|רטיטות|הקשות)\s?(?:ל|ב)דקה|bpm{$end}|ipm{$end})", 'mode' => 'suffix'],
            ['dimension' => 'torque', 'factor' => 1.0, 'unit' => "(?:N\.?m{$end}|ניוטון(?:\s?מטר)?)", 'mode' => 'suffix'],
            ['dimension' => 'mass', 'factor' => 1.0, 'unit' => "(?:kg{$end}|ק{$q}ג|קילוגרם|קילו(?![\p{L}]))", 'mode' => 'suffix'],
            ['dimension' => 'mass', 'factor' => 0.001, 'unit' => "(?:גרם|g{$end})", 'mode' => 'suffix'],
            ['dimension' => 'energy', 'factor' => 1.0, 'unit' => "(?:J{$end}|ג{$a}ו?אול|joule{$end})", 'mode' => 'suffix'],
            ['dimension' => 'pressure', 'factor' => 1.0, 'unit' => "(?:bar{$end}|בר(?![\p{L}]))", 'mode' => 'suffix'],
            ['dimension' => 'pressure', 'factor' => 0.0689476, 'unit' => "psi{$end}", 'mode' => 'suffix'],
            ['dimension' => 'volume', 'factor' => 1.0, 'unit' => "(?:ליטר(?:ים)?|L{$end})", 'mode' => 'suffix'],
            ['dimension' => 'volume', 'factor' => 0.001, 'unit' => "(?:ml{$end}|מ{$q}ל)", 'mode' => 'suffix'],
            // A stone's weight: "0.31 קראט", "1.5ct". Stores also spell gold's karat "קראט"; the
            // vocabulary's range for a stone (well under 9) keeps "14 קראט" out.
            ['dimension' => 'carat', 'factor' => 1.0, 'unit' => "(?:ct{$end}|carats?{$end}|קראט(?![\p{L}]))", 'mode' => 'suffix'],
            // Pack sizes: "100 יח'", "10 יחידות", "700 יחידות".
            ['dimension' => 'count', 'factor' => 1.0, 'unit' => "(?:יח{$a}|יחידות|יחידה(?![\p{L}])|pcs{$end})", 'mode' => 'suffix'],
        ];
    }

    /** @return list<float> every factor that converts some spelling of $dimension to its canonical unit */
    public static function factors(string $dimension): array
    {
        $factors = [1.0];
        foreach (self::spellings() as $spelling) {
            if ($spelling['dimension'] === $dimension) {
                $factors[] = $spelling['factor'];
            }
        }

        return array_values(array_unique($factors, SORT_REGULAR));
    }
}
