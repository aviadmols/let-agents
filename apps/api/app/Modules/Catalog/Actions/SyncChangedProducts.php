<?php

namespace App\Modules\Catalog\Actions;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Events\CatalogProductsChanged;
use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Catalog\Support\FeedRecord;
use App\Modules\Connections\Contracts\ReadsChangedProducts;
use App\Modules\Connections\Contracts\StoreFeed;
use App\Modules\Connections\Contracts\StoreFeedUnavailable;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Enums\RunStatus;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Runs\Models\Run;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * A new or edited product reaches the catalog and the search within minutes, not at night: the
 * store is asked what changed since the last look (catalog.live_sync, every few minutes), and only
 * that is saved, the same way the nightly read saves it (SyncCatalog::saveProducts). A product
 * taken off the store is marked removed. Nothing is written to the activity log when nothing
 * changed. A platform that cannot say what changed waits for the night.
 *
 * Categories, pages and anything the nightly read decides from the whole store (removed products
 * that left no trace, a store behind a password) stay the nightly read's.
 */
final class SyncChangedProducts
{
    public const AGENT = 'catalog.syncer';

    public const ACTION = 'catalog.sync_changes';

    /** Overlap with the last look, so a change saved while the store was being read is not missed. */
    private const OVERLAP_MINUTES = 3;

    /** The first look of a shop with no read at all goes back this far; the nightly read covers the rest. */
    private const FIRST_LOOK_HOURS = 24;

    /** A burst bigger than this (a bulk edit, an import) is the nightly read's. */
    private const MAX_RECORDS = 500;

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
        private readonly StoreFeed $feed,
        private readonly SyncCatalog $sync,
    ) {}

    /** The run when something changed, null when nothing did or the store cannot say. */
    public function handle(string $shopId, RunTrigger $trigger = RunTrigger::Schedule): ?Run
    {
        if (! Features::enabled('catalog.live_sync', $shopId) || ! $this->feed instanceof ReadsChangedProducts) {
            return null;
        }

        $connection = $this->tenant->run($shopId, fn () => StoreConnection::query()->first());

        if ($connection === null) {
            return null;
        }

        $looked = now();
        $since = $this->since($shopId)->copy()->subMinutes(self::OVERLAP_MINUTES);

        try {
            $pages = $this->feed->changedProducts($connection, $since);

            if ($pages === null) {
                return null;
            }

            $records = [];
            $gone = [];

            foreach ($pages as $page) {
                $records = [...$records, ...array_values(array_filter($page['records'], fn ($r): bool => is_array($r) && isset($r['external_id'])))];
                $gone = [...$gone, ...$page['gone']];

                if (count($records) > self::MAX_RECORDS) {
                    // Too many at once: the nightly read takes them; the next look starts from now.
                    Cache::forever(self::key($shopId), $looked->toIso8601String());

                    return null;
                }
            }
        } catch (StoreFeedUnavailable) {
            // The nightly read reports a store that cannot be read; a quick look stays quiet.
            return null;
        }

        Cache::forever(self::key($shopId), $looked->toIso8601String());

        // Only what is really different from the catalog: the same product read again, or one
        // already marked removed, is not a change.
        [$records, $gone] = $this->tenant->run($shopId, fn (): array => $this->different($records, $gone));

        if ($records === [] && $gone === []) {
            return null;
        }

        return $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            trigger: $trigger,
            work: fn (RunContext $run) => $this->tenant->run($shopId, fn () => $this->save($shopId, $records, $gone, $run)),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @param  list<string>  $gone
     */
    private function save(string $shopId, array $records, array $gone, RunContext $run): void
    {
        $limit = (int) Settings::get('catalog.max_products', $shopId);
        $room = max(0, $limit - CatalogProduct::query()->whereNull('removed_at')->count());
        $known = CatalogProduct::query()->whereIn('external_id', array_column($records, 'external_id'))->pluck('external_id')->map(fn ($id): string => (string) $id)->all();
        // A product new to the catalog only while it has room under the shop's cap, like the nightly read.
        $records = array_values(array_filter($records, function (array $record) use ($known, &$room): bool {
            if (in_array((string) $record['external_id'], $known, true)) {
                return true;
            }

            return $room-- > 0;
        }));

        $outcomes = $records === [] ? [] : $this->sync->saveProducts($shopId, $records, CatalogCategory::query()->pluck('id', 'external_id'));
        $removed = $gone === [] ? 0 : CatalogProduct::query()->whereIn('external_id', $gone)->whereNull('removed_at')->update(['removed_at' => now()]);

        $counts = array_count_values($outcomes) + ['created' => 0, 'updated' => 0, 'unchanged' => 0];
        // Numeric IDs come back from array keys as numbers: strings again, as everywhere else.
        $changed = array_map('strval', array_keys(array_filter($outcomes, fn (string $outcome): bool => $outcome !== 'unchanged')));

        $run->output(['created' => $counts['created'], 'updated' => $counts['updated'], 'removed' => $removed, 'products' => array_slice([...$changed, ...$gone], 0, 50)])
            ->summary('catalog::runs.changes_synced', ['created' => (string) $counts['created'], 'updated' => (string) $counts['updated'], 'removed' => (string) $removed]);

        if ($changed !== [] || $removed > 0) {
            event(new CatalogProductsChanged($shopId, array_values(array_unique([...$changed, ...$gone]))));
        }
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @param  list<string>  $gone
     * @return array{0: list<array<string, mixed>>, 1: list<string>}
     */
    private function different(array $records, array $gone): array
    {
        $hashes = CatalogProduct::query()->whereNull('removed_at')
            ->whereIn('external_id', [...array_map(fn (array $r): string => (string) $r['external_id'], $records), ...$gone])
            ->pluck('hash', 'external_id')->mapWithKeys(fn ($hash, $id): array => [(string) $id => (string) $hash]);

        return [
            array_values(array_filter($records, fn (array $r): bool => $hashes->get((string) $r['external_id']) !== FeedRecord::product($r)['hash'])),
            array_values(array_filter(array_unique($gone), fn (string $id): bool => $hashes->has($id))),
        ];
    }

    /** When the store was last asked: the last quick look, else the last full read, else a day ago. */
    private function since(string $shopId): CarbonInterface
    {
        $kept = Cache::get(self::key($shopId));

        if (is_string($kept)) {
            return Carbon::parse($kept);
        }

        // The first look goes back a day, or to the last full read if that is older: a full read
        // that never reached the newest products (they come last) is made good here.
        $full = Run::query()->forShop($shopId)->where('action', SyncCatalog::ACTION)->where('status', RunStatus::Succeeded)->latest('started_at')->value('started_at');
        $day = now()->subHours(self::FIRST_LOOK_HOURS);

        return $full !== null && Carbon::parse($full)->lt($day) ? Carbon::parse($full) : $day;
    }

    private static function key(string $shopId): string
    {
        return 'catalog:changes_looked:'.$shopId;
    }
}
