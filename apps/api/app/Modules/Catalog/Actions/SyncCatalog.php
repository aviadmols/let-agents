<?php

namespace App\Modules\Catalog\Actions;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Events\CatalogProductsChanged;
use App\Modules\Catalog\Events\CatalogUpdated;
use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Catalog\Support\FeedRecord;
use App\Modules\Connections\Contracts\StoreFeed;
use App\Modules\Connections\Contracts\StoreFeedUnavailable;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Runs\Models\Run;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reads a shop's whole catalog from its store feed: categories, published products and shared
 * content. Unchanged records (same hash) are left alone. Records the store no longer publishes
 * are marked removed, never deleted, and only after a complete read: a sync that stopped half
 * way must not remove what it did not get to.
 */
final class SyncCatalog
{
    public const AGENT = 'catalog.syncer';

    public const ACTION = 'catalog.sync';

    public const REFRESH = 'catalog.refresh';

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
        private readonly StoreFeed $feed,
    ) {}

    public function handle(string $shopId, RunTrigger $trigger = RunTrigger::Manual): Run
    {
        return $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            trigger: $trigger,
            work: fn (RunContext $run) => $this->tenant->run($shopId, fn () => $this->sync($shopId, $run)),
        );
    }

    /**
     * The products alone, between nights (catalog.refresh): prices, stock and what the store took
     * off, so a sale or a stock update the store made without marking the product changed (an
     * inventory system writing straight to it) is not a day late. No categories, pages or posts,
     * and the search is rebuilt only when a product really changed. The run is written only then.
     */
    public function refresh(string $shopId, RunTrigger $trigger = RunTrigger::Schedule): ?Run
    {
        $connection = $this->tenant->run($shopId, fn () => StoreConnection::query()->first());

        if ($connection === null) {
            return null;
        }

        try {
            $changed = [];
            $products = $this->tenant->run($shopId, function () use ($shopId, $connection, &$changed): array {
                return $this->syncProducts($shopId, $connection, $changed);
            });
        } catch (StoreFeedUnavailable) {
            // The nightly read reports a store that cannot be read; a refresh stays quiet.
            return null;
        }

        if ($changed === [] && $products['removed'] === 0) {
            return null;
        }

        return $this->runs->track(
            agent: self::AGENT,
            action: self::REFRESH,
            shopId: $shopId,
            trigger: $trigger,
            work: function (RunContext $run) use ($shopId, $products, $changed): void {
                $run->output(['products' => $products, 'changed' => array_slice($changed, 0, 50)])
                    ->summary('catalog::runs.refreshed', ['updated' => (string) ($products['updated'] + $products['created']), 'removed' => (string) $products['removed']]);
                event(new CatalogProductsChanged($shopId, $changed));
            },
        );
    }

    private function sync(string $shopId, RunContext $run): void
    {
        $connection = StoreConnection::query()->first();

        if ($connection === null) {
            $run->fail('catalog::runs.no_connection');

            return;
        }

        $run->output(['site_url' => $connection->site_url]);

        try {
            $categories = $this->syncCategories($shopId, $connection);
            $products = $this->syncProducts($shopId, $connection);
            $content = $this->syncContent($shopId, $connection);
        } catch (StoreFeedUnavailable $e) {
            $run->fail($e->summaryKey(), $e->summaryParams(), $e->getMessage());

            return;
        }

        $run->output(compact('categories', 'products', 'content'))->summary(
            $products['capped'] ? 'catalog::runs.synced_capped' : 'catalog::runs.synced',
            [
                'products' => number_format($products['total']),
                'created' => number_format($products['created']),
                'updated' => number_format($products['updated']),
                'removed' => number_format($products['removed']),
                'categories' => number_format($categories['total']),
                'content' => number_format($content['total']),
                'limit' => number_format((int) Settings::get('catalog.max_products', $shopId)),
            ],
        );

        // What reads the catalogue (the search box) follows now, not at night.
        event(new CatalogUpdated($shopId));
    }

    /** @return array{total: int, created: int, updated: int, unchanged: int, removed: int} */
    private function syncCategories(string $shopId, StoreConnection $connection): array
    {
        $records = $this->feed->categories($connection);
        $existing = CatalogCategory::query()->get()->keyBy('external_id');
        $counts = ['total' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'removed' => 0];
        $seen = [];

        DB::transaction(function () use ($records, $existing, $shopId, &$counts, &$seen): void {
            foreach ($records as $record) {
                if (! is_array($record) || ! isset($record['external_id'])) {
                    continue;
                }

                $attributes = FeedRecord::category($record);
                $seen[$attributes['external_id']] = true;
                $counts['total']++;
                $counts[$this->upsert(CatalogCategory::class, $existing->get($attributes['external_id']), $attributes + ['shop_id' => $shopId])]++;
            }
        });

        $counts['removed'] = $this->markRemoved(CatalogCategory::query(), $seen);

        return $counts;
    }

    /** @return array{total: int, created: int, updated: int, unchanged: int, removed: int, capped: bool} */
    /** @param  list<string>  $changed  filled with the products created or updated */
    private function syncProducts(string $shopId, StoreConnection $connection, array &$changed = []): array
    {
        $limit = (int) Settings::get('catalog.max_products', $shopId);
        $categoryIds = CatalogCategory::query()->pluck('id', 'external_id');
        $counts = ['total' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'removed' => 0, 'capped' => false];
        $seen = [];

        foreach ($this->feed->products($connection) as $page) {
            $page = array_values(array_filter($page, fn ($r): bool => is_array($r) && isset($r['external_id'])));

            if ($limit < $counts['total'] + count($page)) {
                $page = array_slice($page, 0, max(0, $limit - $counts['total']));
                $counts['capped'] = true;
            }

            foreach ($this->saveProducts($shopId, $page, $categoryIds) as $externalId => $outcome) {
                $seen[$externalId] = true;
                $counts['total']++;
                $counts[$outcome]++;
                if ($outcome !== 'unchanged') {
                    $changed[] = (string) $externalId;
                }
            }

            if ($counts['capped']) {
                break;
            }
        }

        // A capped read did not see everything, so nothing can be called removed.
        if (! $counts['capped']) {
            $counts['removed'] = $this->markRemoved(CatalogProduct::query(), $seen);
        }

        return $counts;
    }

    /** @return array{total: int, created: int, updated: int, unchanged: int, removed: int, types: list<string>} */
    private function syncContent(string $shopId, StoreConnection $connection): array
    {
        $limit = (int) Settings::get('catalog.max_content_items', $shopId);
        $types = $this->feed->contentTypes($connection);
        $counts = ['total' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'removed' => 0, 'types' => $types];
        $seen = [];
        $capped = false;

        foreach ($types as $type) {
            foreach ($this->feed->content($connection, $type) as $page) {
                $existing = CatalogContent::query()->where('type', $type)->get()->keyBy('external_id');

                foreach ($page as $record) {
                    if (! is_array($record) || ! isset($record['external_id'])) {
                        continue;
                    }

                    if ($counts['total'] >= $limit) {
                        $capped = true;
                        break 3;
                    }

                    $attributes = FeedRecord::content($record + ['type' => $type]);
                    $seen[$type.':'.$attributes['external_id']] = true;
                    $counts['total']++;
                    $counts[$this->upsert(CatalogContent::class, $existing->get($attributes['external_id']), $attributes + ['shop_id' => $shopId])]++;
                }
            }
        }

        if (! $capped) {
            $counts['removed'] = CatalogContent::query()
                ->whereNull('removed_at')
                ->get(['id', 'type', 'external_id'])
                // Content the platform wrote for the store is not the feed's to remove.
                ->reject(fn (CatalogContent $c): bool => isset($seen[$c->type.':'.$c->external_id]) || str_starts_with((string) $c->external_id, CatalogContent::OWN_PREFIX))
                ->each(fn (CatalogContent $c) => $c->forceFill(['removed_at' => now()])->save())
                ->count();
        }

        return $counts;
    }

    /**
     * One page of product records saved: new ones created, changed ones updated with their
     * categories, the same ones left alone. The quick read of changed products saves with it too
     * (SyncChangedProducts), so both reads save a product the same way. Inside the shop's tenant.
     *
     * @param  list<array<string, mixed>>  $page
     * @param  Collection<string, string>  $categoryIds  category external ID => its row ID
     * @return array<string, 'created'|'updated'|'unchanged'> external ID => what happened
     */
    public function saveProducts(string $shopId, array $page, Collection $categoryIds): array
    {
        $existing = CatalogProduct::query()
            ->whereIn('external_id', array_map(fn (array $r): string => (string) $r['external_id'], $page))
            ->get()
            ->keyBy('external_id');
        $outcomes = [];

        DB::transaction(function () use ($page, $existing, $shopId, $categoryIds, &$outcomes): void {
            foreach ($page as $record) {
                $attributes = FeedRecord::product($record);
                $current = $existing->get($attributes['external_id']);
                $outcome = $this->upsert(CatalogProduct::class, $current, $attributes + ['shop_id' => $shopId]);
                $outcomes[(string) $attributes['external_id']] = $outcome;

                if ($outcome !== 'unchanged') {
                    /** @var CatalogProduct $product */
                    $product = $current ?? CatalogProduct::query()->where('external_id', $attributes['external_id'])->firstOrFail();
                    $product->categories()->sync(
                        collect(FeedRecord::productCategoryIds($record))->map(fn (string $id) => $categoryIds->get($id))->filter()->values()->all(),
                    );
                }
            }
        });

        return $outcomes;
    }

    /**
     * @param  class-string<CatalogCategory|CatalogProduct|CatalogContent>  $model
     * @param  array<string, mixed>  $attributes
     * @return 'created'|'updated'|'unchanged'
     */
    private function upsert(string $model, ?object $current, array $attributes): string
    {
        $now = now();

        if ($current === null) {
            $model::query()->create($attributes + ['synced_at' => $now]);

            return 'created';
        }

        if ($current->hash === $attributes['hash'] && $current->removed_at === null) {
            return 'unchanged';
        }

        $current->forceFill($attributes + ['synced_at' => $now, 'removed_at' => null])->save();

        return 'updated';
    }

    /**
     * @param  Builder<CatalogCategory>|Builder<CatalogProduct>  $query
     * @param  array<string, true>  $seen  external IDs the feed returned
     */
    private function markRemoved($query, array $seen): int
    {
        return $query->whereNull('removed_at')
            ->get(['id', 'external_id'])
            ->reject(fn ($record): bool => isset($seen[$record->external_id]))
            ->each(fn ($record) => $record->forceFill(['removed_at' => now()])->save())
            ->count();
    }
}
