<?php

namespace App\Modules\Admin\Contracts;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * One scale for every line on the shop's analytics chart (searches, openings of Lets, questions, adds
 * to the cart), so they read alike: hours for a day, days up to four months, months beyond, in Israel
 * time. A module counts its own by key() and returns them under figures()['series'][its name]; the
 * screen turns them into points().
 */
final class ReportSeries
{
    public const TZ = 'Asia/Jerusalem';

    /** @return array{unit: string, format: string, label: string} */
    public static function scale(CarbonInterface $since, CarbonInterface $until): array
    {
        $span = Carbon::instance($since)->diffInDays($until, true);

        return match (true) {
            $span <= 1.01 => ['unit' => 'hour', 'format' => 'Y-m-d H', 'label' => 'H:00'],
            $span <= 124 => ['unit' => 'day', 'format' => 'Y-m-d', 'label' => 'j.n'],
            default => ['unit' => 'month', 'format' => 'Y-m', 'label' => 'n.Y'],
        };
    }

    /** The key a moment is counted under, at the period's scale. */
    public static function key(CarbonInterface|string $at, string $format): string
    {
        return Carbon::parse($at)->timezone(self::TZ)->format($format);
    }

    /**
     * Every step of the period with its count, the empty ones too.
     *
     * @param  array<string, int>  $counts  by key()
     * @return list<array{day: string, label: string, n: int}>
     */
    public static function points(CarbonInterface $since, CarbonInterface $until, array $counts): array
    {
        ['unit' => $unit, 'format' => $format, 'label' => $label] = self::scale($since, $until);
        $points = [];

        for ($at = Carbon::instance($since)->timezone(self::TZ)->startOf($unit); $at->lt($until) && count($points) < 400; $at->add(1, $unit)) {
            $points[] = ['day' => $at->format($format), 'label' => $at->format($label), 'n' => (int) ($counts[$at->format($format)] ?? 0)];
        }

        return $points;
    }
}
