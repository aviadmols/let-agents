<?php

namespace App\Modules\Catalog\Console;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Actions\SyncCatalog;
use App\Modules\Catalog\Jobs\SyncCatalogJob;
use App\Modules\Connections\Enums\ConnectionStatus;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Console\Command;

/**
 * catalog:sync gueta-avigdor      sync one shop now, in this process
 * catalog:sync --scheduled        queue a sync for every connected shop with daily_sync on
 */
final class SyncCatalogCommand extends Command
{
    protected $signature = 'catalog:sync {shop? : shop slug or ID} {--scheduled : queue every connected shop}';

    protected $description = 'Read shop catalogs from their store feeds.';

    public function handle(SyncCatalog $sync, TenantContext $tenant): int
    {
        if ($this->option('scheduled')) {
            // Plugin stores once their connection was tested; Shopify stores from install on, since
            // the app's token is their connection and no plugin test ever marks them.
            $shopIds = $tenant->runUnscoped(fn () => StoreConnection::query()
                ->where(fn ($q) => $q->where('status', ConnectionStatus::Connected)->orWhere('platform', 'shopify'))
                ->whereHas('shop', fn ($shop) => $shop->where('status', 'active'))
                ->pluck('shop_id'));

            foreach ($shopIds as $shopId) {
                if (Features::enabled('catalog.daily_sync', $shopId)) {
                    SyncCatalogJob::dispatch($shopId, RunTrigger::Schedule);
                }
            }

            $this->info("Queued {$shopIds->count()} shop(s).");

            return self::SUCCESS;
        }

        $key = (string) $this->argument('shop');
        $shop = Shop::query()->where('slug', $key)->orWhere('id', $key)->first();

        if ($shop === null) {
            $this->error("No shop \"{$key}\".");

            return self::FAILURE;
        }

        $run = $sync->handle($shop->id);
        $this->line((string) $run->summary());

        return $run->status->value === 'succeeded' ? self::SUCCESS : self::FAILURE;
    }
}
