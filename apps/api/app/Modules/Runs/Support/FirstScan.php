<?php

namespace App\Modules\Runs\Support;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Contracts\ScanDetails;
use App\Modules\Runs\Enums\RunStatus;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Runs\Jobs\FirstScanStep;
use App\Modules\Runs\Models\Run;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Throwable;

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

    /**
     * The shop manager's scan after the first: what changed and what is new. Each of these steps
     * already reads only what changed since it last ran (an unchanged product is not read again);
     * what the store says about itself, its posts and the page tags are left to the night. The
     * whole chain again is the operator's (start(full: true)).
     */
    public const CHANGES = ['catalog', 'products', 'pages', 'search'];

    /** A manager starts it again at most this often; the night runs it anyway. */
    public const AGAIN_AFTER_HOURS = 1;

    private const KEEP_DAYS = 7;

    /** False while a scan started from here is still under way, or started too recently. */
    public static function canStart(Shop $shop): bool
    {
        return ! Cache::has(self::key($shop->id, 'lock'));
    }

    /**
     * The whole chain for a new shop, or when an operator asks for it; otherwise the changes only.
     * An operator's full scan goes ahead even while a manager's is locked.
     */
    public static function start(Shop $shop, bool $full = false): bool
    {
        // Only an operator's full scan passes the lock; a new shop's manager scans it all, once.
        if (! Cache::add(self::key($shop->id, 'lock'), true, now()->addHours(self::AGAIN_AFTER_HOURS)) && ! $full) {
            return false;
        }

        $full = $full || self::isNew($shop);

        $steps = $full ? self::STEPS : array_intersect_key(self::STEPS, array_flip(self::CHANGES));
        Cache::put(self::key($shop->id, 'started'), now()->toIso8601String(), now()->addDays(self::KEEP_DAYS));
        Cache::put(self::key($shop->id, 'steps'), array_keys($steps), now()->addDays(self::KEEP_DAYS));

        foreach (array_keys(self::STEPS) as $step) {
            Cache::forget(self::key($shop->id, 'began:'.$step));
            Cache::forget(self::key($shop->id, 'ended:'.$step));
            Cache::forget(self::key($shop->id, 'step:'.$step));
        }

        foreach (array_keys($steps) as $step) {
            self::mark($shop->id, $step, self::WAITING);
        }

        $jobs = [];
        foreach ($steps as $step => [$command, $parameters]) {
            $jobs[] = new FirstScanStep($shop->id, $step, $command, array_map(fn (string $value): string => str_replace('{shop}', (string) $shop->slug, $value), $parameters));
        }

        Bus::chain($jobs)->onQueue('long')->dispatch();

        return true;
    }

    public static function mark(string $shopId, string $step, string $state): void
    {
        Cache::put(self::key($shopId, 'step:'.$step), $state, now()->addDays(self::KEEP_DAYS));

        // When the step began and ended, to find what its runs reported.
        if ($state === self::RUNNING || $state === self::DONE || $state === self::FAILED) {
            Cache::put(self::key($shopId, ($state === self::RUNNING ? 'began:' : 'ended:').$step), now()->toIso8601String(), now()->addDays(self::KEEP_DAYS));
        }
    }

    /**
     * What each step reported, in the viewer's language: the summary line of every run the step
     * made ("Read 1,240 products: 30 new …"), from when it began until the next step began, and a
     * few minutes more for the last. Lines about models, tokens or money are the operator's and
     * stay out.
     *
     * @return array<string, list<string>>
     */
    public static function notes(Shop $shop): array
    {
        $steps = self::steps($shop);
        $notes = [];

        foreach ($steps as $i => $step) {
            $began = Cache::get(self::key($shop->id, 'began:'.$step));

            if ($began === null) {
                continue;
            }

            $next = isset($steps[$i + 1]) ? Cache::get(self::key($shop->id, 'began:'.$steps[$i + 1])) : null;
            $ended = Cache::get(self::key($shop->id, 'ended:'.$step));
            $until = $next !== null ? Carbon::parse($next) : ($ended !== null ? Carbon::parse($ended)->addMinutes(15) : now());

            $notes[$step] = Run::query()
                ->where('shop_id', $shop->id)
                ->whereBetween('created_at', [Carbon::parse($began)->subSecond(), $until])
                ->whereNotNull('summary_key')
                ->orderBy('created_at')
                ->get()
                ->map(fn (Run $run): string => trim((string) $run->summary()))
                ->reject(fn (string $line): bool => $line === '' || preg_match('/\$|\btokens?\b|gpt-|claude|gemini|openai|anthropic|מודל|model/iu', $line) === 1)
                ->unique()
                ->take(3)
                ->values()
                ->all();
        }

        return $notes;
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
        foreach (self::steps($shop) as $step) {
            $steps[$step] = (string) Cache::get(self::key($shop->id, 'step:'.$step), self::WAITING);
        }

        return [
            'started' => Carbon::parse($started),
            'steps' => $steps,
            'finished' => ! in_array(self::WAITING, $steps, true) && ! in_array(self::RUNNING, $steps, true),
        ];
    }

    /**
     * The steps of the scan last started here, in order; every step when none was.
     *
     * @return list<string>
     */
    public static function steps(Shop $shop): array
    {
        $steps = Cache::get(self::key($shop->id, 'steps'));

        return is_array($steps) && $steps !== [] ? array_values(array_intersect(array_keys(self::STEPS), $steps)) : array_keys(self::STEPS);
    }

    /**
     * What each step learned, from every module that answers for it (Admin\Contracts\ScanDetails),
     * inside the shop. A module that fails is left out.
     *
     * @return array<string, list<array{title: string, rows: list<array{0: string, 1: string}>}>>
     */
    public static function details(Shop $shop): array
    {
        $details = [];

        foreach (app()->tagged(ScanDetails::TAG) as $source) {
            try {
                foreach (app(TenantContext::class)->run((string) $shop->id, fn (): array => $source->scanDetails($shop)) as $step => $sections) {
                    $details[$step] = [...($details[$step] ?? []), ...$sections];
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $details;
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
