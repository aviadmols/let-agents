<?php

namespace App\Modules\Catalog\Actions;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Events\CatalogProductsChanged;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Catalog\Models\CatalogVariant;
use App\Modules\Connections\Contracts\ReadsStock;
use App\Modules\Connections\Contracts\StoreFeed;
use App\Modules\Connections\Contracts\StoreFeedUnavailable;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Runs\Models\Run;
use Illuminate\Support\Facades\Cache;

/**
 * What can be bought now, read on its own several times a day (catalog.stock_checks, every
 * catalog.stock_every_hours): only whether each product and its variants are in stock, a read light
 * enough to repeat, where a store's stock changes without its products being marked changed (Shopify
 * keeps inventory apart). Only what changed is written, and only then is the search told
 * (CatalogProductsChanged) and a run kept. A platform with no such read waits for the products it reads.
 */
final class SyncStock
{
    public const AGENT = 'catalog.syncer';

    public const ACTION = 'catalog.stock';

    private const CHECKED = 'catalog:stock_checked:';

    /** A check due a few minutes early still runs: the schedule's hour is not to the second. */
    private const EARLY_SECONDS = 300;

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
        private readonly StoreFeed $feed,
    ) {}

    public function handle(string $shopId, RunTrigger $trigger = RunTrigger::Schedule, bool $now = false): ?Run
    {
        if (! Features::enabled('catalog.stock_checks', $shopId) || ! $this->feed instanceof ReadsStock) {
            return null;
        }

        $every = (int) Settings::get('catalog.stock_every_hours', $shopId) * 3600 - self::EARLY_SECONDS;
        $last = (int) Cache::get(self::CHECKED.$shopId, 0);
        if (! $now && $last > 0 && time() - $last < $every) {
            return null;
        }

        $connection = $this->tenant->run($shopId, fn () => StoreConnection::query()->first());
        if ($connection === null) {
            return null;
        }

        $counts = ['products' => 0, 'variants' => 0, 'read' => 0];
        $changed = [];

        try {
            $pages = $this->feed->stock($connection);
            if ($pages === null) {
                return null;
            }

            foreach ($pages as $page) {
                $counts['read'] += count($page);
                $this->tenant->run($shopId, function () use ($page, &$counts, &$changed): void {
                    foreach (CatalogProduct::query()->whereNull('removed_at')->whereIn('external_id', array_map('strval', array_keys($page)))->get(['id', 'external_id', 'in_stock']) as $product) {
                        $now = (bool) $page[(string) $product->external_id]['in_stock'];
                        if ($product->in_stock !== $now) {
                            $product->forceFill(['in_stock' => $now])->save();
                            $counts['products']++;
                            $changed[(string) $product->external_id] = true;
                        }
                    }

                    $variants = [];
                    foreach ($page as $productId => $stock) {
                        foreach ($stock['variants'] as $variantId => $inStock) {
                            $variants[(string) $variantId] = [(string) $productId, (bool) $inStock];
                        }
                    }
                    foreach (CatalogVariant::query()->whereIn('external_id', array_keys($variants))->get(['id', 'external_id', 'in_stock']) as $variant) {
                        [$productId, $now] = $variants[(string) $variant->external_id];
                        if ($variant->in_stock !== $now) {
                            $variant->forceFill(['in_stock' => $now])->save();
                            $counts['variants']++;
                            $changed[$productId] = true;
                        }
                    }
                });
            }
        } catch (StoreFeedUnavailable) {
            // The nightly read reports a store that cannot be read; a stock check stays quiet.
            return null;
        }

        Cache::put(self::CHECKED.$shopId, time(), now()->addDays(2));

        if ($changed === []) {
            return null;
        }

        return $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            trigger: $trigger,
            work: function (RunContext $run) use ($shopId, $counts, $changed): void {
                $run->output($counts + ['changed' => array_slice(array_keys($changed), 0, 50)])
                    ->summary('catalog::runs.stock', ['products' => (string) count($changed)]);
                event(new CatalogProductsChanged($shopId, array_map('strval', array_keys($changed))));
            },
        );
    }
}
