<?php

namespace App\Modules\Runs\Support;

use App\Modules\Runs\Enums\RunStatus;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Runs\Jobs\FirstScanStep;
use App\Modules\Runs\Models\Run;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

/**
 * The shop's scan and learning, started by its manager from the overview rather than waiting for
 * the night: the catalogue, what the products say, the pages and pictures, the search, what the
 * store says about itself, and the tags. The same commands the schedule runs, for one shop, one
 * after another on the long queue. Where it is lives in the cache for a week; nothing about models
 * or costs is kept here or shown.
 */
final class FirstScan
{
    public const WAITING = 'waiting';

    public const RUNNING = 'running';

    public const DONE = 'done';

    public const FAILED = 'failed';

    /** step => the command and its arguments; {shop} is the shop's slug. */
    public const STEPS = [
        'catalog' => ['catalog:sync', ['shop' => '{shop}']],
        'products' => ['enrichment', ['step' => 'nightly', 'target' => '{shop}']],
        'pages' => ['retrieval', ['step' => 'nightly', 'target' => '{shop}']],
        'search' => ['search', ['step' => 'index', 'target' => '{shop}']],
        'knowledge' => ['assistant:knowledge', ['shop' => '{shop}']],
        'posts' => ['assistant:read-posts', ['shop' => '{shop}']],
        'tags' => ['search', ['step' => 'tags', 'target' => '{shop}']],
    ];

    /** A manager starts it again at most this often; the night runs it anyway. */
    public const AGAIN_AFTER_HOURS = 6;

    private const KEEP_DAYS = 7;

    /** False while a scan started from here is still under way, or started too recently. */
    public static function canStart(Shop $shop): bool
    {
        return ! Cache::has(self::key($shop->id, 'lock'));
    }

    public static function start(Shop $shop): bool
    {
        if (! Cache::add(self::key($shop->id, 'lock'), true, now()->addHours(self::AGAIN_AFTER_HOURS))) {
            return false;
        }

        Cache::put(self::key($shop->id, 'started'), now()->toIso8601String(), now()->addDays(self::KEEP_DAYS));

        foreach (array_keys(self::STEPS) as $step) {
            self::mark($shop->id, $step, self::WAITING);
        }

        $jobs = [];
        foreach (self::STEPS as $step => [$command, $parameters]) {
            $jobs[] = new FirstScanStep($shop->id, $step, $command, array_map(fn (string $value): string => str_replace('{shop}', (string) $shop->slug, $value), $parameters));
        }

        Bus::chain($jobs)->onQueue('long')->dispatch();

        return true;
    }

    public static function mark(string $shopId, string $step, string $state): void
    {
        Cache::put(self::key($shopId, 'step:'.$step), $state, now()->addDays(self::KEEP_DAYS));
    }

    /**
     * Where the scan is: when it started and each step's state, or null when none was started here
     * this week.
     *
     * @return array{started: Carbon, steps: array<string, string>, finished: bool}|null
     */
    public static function state(Shop $shop): ?array
    {
        $started = Cache::get(self::key($shop->id, 'started'));

        if ($started === null) {
            return null;
        }

        $steps = [];
        foreach (array_keys(self::STEPS) as $step) {
            $steps[$step] = (string) Cache::get(self::key($shop->id, 'step:'.$step), self::WAITING);
        }

        return [
            'started' => Carbon::parse($started),
            'steps' => $steps,
            'finished' => ! in_array(self::WAITING, $steps, true) && ! in_array(self::RUNNING, $steps, true),
        ];
    }

    /** A shop the night has not scanned yet: the overview offers the scan as its first thing to do. */
    public static function isNew(Shop $shop): bool
    {
        return ! Run::query()->where('shop_id', $shop->id)->where('status', RunStatus::Succeeded)->where('trigger', RunTrigger::Schedule)->exists();
    }

    private static function key(string $shopId, string $what): string
    {
        return "first-scan:{$shopId}:{$what}";
    }
}
