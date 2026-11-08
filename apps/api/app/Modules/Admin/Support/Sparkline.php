<?php

namespace App\Modules\Admin\Support;

/**
 * The soft line under Today's big number, as the design draws it: a 560×86 box, the line between 10 and 70, a smooth
 * curve through every step (Catmull-Rom as cubic Béziers), the area under it, and a dot on the best step. Drawn on
 * the server so the page needs no chart script; in Hebrew the stylesheet mirrors it, so it runs right to left.
 */
final class Sparkline
{
    public const WIDTH = 560;

    public const HEIGHT = 86;

    private const TOP = 10;

    private const BASE = 70;

    /**
     * @param  list<float>  $values  one per step of the period
     * @return array{stroke: string, area: string, dot: array{x: float, y: float}|null}
     */
    public static function of(array $values, ?int $best = null): array
    {
        $n = count($values);
        if ($n === 0) {
            return ['stroke' => '', 'area' => '', 'dot' => null];
        }
        $max = max($values) > 0 ? max($values) : 1.0;
        $points = [];
        foreach ($values as $i => $value) {
            $x = $n === 1 ? self::WIDTH / 2 : $i * self::WIDTH / ($n - 1);
            $points[] = [round($x, 1), round(self::BASE - (max(0, $value) / $max) * (self::BASE - self::TOP), 1)];
        }
        if ($n === 1) {
            $points = [[0.0, $points[0][1]], [(float) self::WIDTH, $points[0][1]]];
        }

        $stroke = 'M'.$points[0][0].' '.$points[0][1];
        for ($i = 0; $i < count($points) - 1; $i++) {
            [$x0, $y0] = $points[max(0, $i - 1)];
            [$x1, $y1] = $points[$i];
            [$x2, $y2] = $points[$i + 1];
            [$x3, $y3] = $points[min(count($points) - 1, $i + 2)];
            $c1 = [round($x1 + ($x2 - $x0) / 6, 1), round(self::clamp($y1 + ($y2 - $y0) / 6), 1)];
            $c2 = [round($x2 - ($x3 - $x1) / 6, 1), round(self::clamp($y2 - ($y3 - $y1) / 6), 1)];
            $stroke .= " C{$c1[0]} {$c1[1]} {$c2[0]} {$c2[1]} {$x2} {$y2}";
        }
        $last = end($points);

        return [
            'stroke' => $stroke,
            'area' => $stroke.' V'.self::BASE.' H0Z',
            'dot' => $best === null || ! isset($points[$best]) || $n === 1 ? null : ['x' => $points[$best][0], 'y' => $points[$best][1]],
        ] + ['end' => $last];
    }

    private static function clamp(float $y): float
    {
        return max(self::TOP - 6, min(self::BASE, $y));
    }
}
