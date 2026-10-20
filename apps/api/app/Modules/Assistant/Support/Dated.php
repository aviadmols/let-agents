<?php

namespace App\Modules\Assistant\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * A line that tells of something still to come on a date already gone: "המשלוח הבא יוצא ב־15.9" read
 * on 20.10, a sale "בתוקף עד 30/9". A store's banner keeps such a line on every page long after its
 * day, and an answer that repeats it misleads. A date of something that happened ("נחטף ב־7.10") is
 * not one: only a date with words of what is still to come. Code only.
 */
final class Dated
{
    /** Words of something still to come, or of a time it holds until. */
    private const AHEAD = '/(?<![\p{L}])(?:יוצא|יוצאת|יוצאים|ייצא|תצא|הבא|הבאה|הקרוב|הקרובה|עד|בתוקף|החל|מתאריך|יישלח|נשלח|תישלח|ישלח|יגיע|תגיע|מגיע|מגיעה|ייפתח|תיפתח|נפתח|מסתיים|יסתיים|next|until|valid|ships?|shipping|arrives?|ends?)(?![\p{L}])/iu';

    /** A day and a month, maybe a year: 15.9, 15/09, 15.9.26, 15.09.2026. */
    private const DATE = '/(?<![\d.\/])(\d{1,2})[.\/](\d{1,2})(?:[.\/](\d{2}|\d{4}))?(?![\d\/]|\.\d)/u';

    /** A date without its year is read as this year's, unless it is more than this many days ahead (then last year's) or more than the rest of a year behind (then next year's). */
    private const AHEAD_DAYS = 200;

    public static function past(string $text, ?CarbonInterface $now = null): bool
    {
        if (preg_match(self::AHEAD, $text) !== 1 || preg_match_all(self::DATE, $text, $dates, PREG_SET_ORDER) === 0) {
            return false;
        }

        $today = ($now ?? Carbon::now())->copy()->startOfDay();

        foreach ($dates as $match) {
            [$day, $month, $year] = [(int) $match[1], (int) $match[2], (string) ($match[3] ?? '')];
            $inYear = $year === '' ? $today->year : (strlen($year) === 2 ? 2000 + (int) $year : (int) $year);
            if (! checkdate($month, $day, $inYear)) {
                continue;
            }
            $date = Carbon::create($inYear, $month, $day)->startOfDay();
            // without a year, the nearest of its days: "5.1" read in October is the coming January
            if ($year === '' && $date->greaterThan($today->copy()->addDays(self::AHEAD_DAYS))) {
                $date = $date->subYear();
            } elseif ($year === '' && $date->lessThan($today->copy()->subDays(365 - self::AHEAD_DAYS))) {
                $date = $date->addYear();
            }
            if ($date->lessThan($today)) {
                return true;
            }
        }

        return false;
    }
}
