<?php

namespace App\Modules\Runs\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * How long a step of the scan takes, learned from the scans that ended, every shop's: so many seconds an item for a
 * step that goes through items (products, pictures, posts, pages), so many seconds in all for one that does not.
 * Until a step has ended somewhere, a first guess (FIRST). Code only: the estimate of when a scan ends is arithmetic
 * on these and on how far each step is (ScanProgress), never a model.
 */
final class ScanTimes
{
    /**
     * step => [seconds an item, seconds in all]: the guess before any scan taught better. A step with items is
     * estimated by them when it has a count, else by its seconds in all.
     *
     * @var array<string, array{0: float|null, 1: int}>
     */
    private const FIRST = [
        'catalog' => [0.4, 120],
        'sample' => [null, 90],
        'site' => [null, 180],
        'knowledge' => [null, 240],
        'posts' => [4.0, 300],
        'pictures' => [1.5, 300],
        'structure' => [null, 420],
        'products' => [6.0, 900],
        'pages' => [1.0, 240],
        'search' => [null, 60],
        'tags' => [null, 180],
        'check' => [null, 240],
        'choices' => [3.0, 300],
    ];

    /** How many ended scans a learned time is the average of: later ones weigh as much as the first. */
    private const MEMORY = 20;

    /** A step never ends sooner than this from now, while it runs: no "0 minutes left" on a step at work. */
    private const LEAST_LEFT = 30;

    /** A step has ended in $seconds, through $items items (0: a step with none, or none counted). */
    public static function learn(string $step, int $seconds, int $items): void
    {
        if ($seconds <= 0 || ! isset(self::FIRST[$step])) {
            return;
        }
        $known = (array) Cache::get(self::key($step), []);
        if ($items > 0 && self::FIRST[$step][0] !== null) {
            $n = min(self::MEMORY, (int) ($known['item_n'] ?? 0));
            $known['item'] = (((float) ($known['item'] ?? 0)) * $n + $seconds / $items) / ($n + 1);
            $known['item_n'] = $n + 1;
        } else {
            $n = min(self::MEMORY, (int) ($known['all_n'] ?? 0));
            $known['all'] = (((float) ($known['all'] ?? 0)) * $n + $seconds) / ($n + 1);
            $known['all_n'] = $n + 1;
        }
        Cache::forever(self::key($step), $known);
    }

    /** How long a step not begun yet will take, with $total items (null: not counted). */
    public static function whole(string $step, ?int $total): int
    {
        $known = (array) Cache::get(self::key($step), []);
        [$item, $all] = self::FIRST[$step] ?? [null, 120];
        if ($item !== null && $total !== null && $total > 0) {
            return (int) ceil(((float) ($known['item'] ?? $item)) * $total);
        }

        return (int) round((float) ($known['all'] ?? $all));
    }

    /** How long a step at work has left: by how fast it went so far where it counts, else by what it usually takes. */
    public static function left(string $step, Carbon $began, int $done, ?int $total): int
    {
        $spent = max(0, (int) $began->diffInSeconds(now()));
        if ($total !== null && $done > 0 && $total > $done && $spent > 0) {
            return max(self::LEAST_LEFT, (int) ceil($spent * ($total - $done) / $done));
        }

        return max(self::LEAST_LEFT, self::whole($step, $total) - $spent);
    }

    private static function key(string $step): string
    {
        return 'first-scan:times:'.$step;
    }
}
