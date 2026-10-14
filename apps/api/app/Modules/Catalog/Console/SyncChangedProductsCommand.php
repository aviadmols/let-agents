<?php

namespace App\Modules\Catalog\Console;

use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Actions\SyncChangedProducts;
use App\Modules\Connections\Enums\ConnectionStatus;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Console\Command;
use Throwable;

/**
 * catalog:sync-changes                 every connected shop: what changed in its store since the last look
 * catalog:sync-changes gueta-avigdor   one shop
 */
final class SyncChangedProductsCommand extends Command
{
    protected $signature = 'catalog:sync-changes {shop? : shop slug or ID}';

    protected $description = 'Read the products each store changed since the last look, so new ones are searchable within minutes.';

    public function handle(SyncChangedProducts $sync, TenantContext $tenant): int
    {
        $key = (string) $this->argument('shop');
        $shopIds = $key !== ''
            ? Shop::query()->where('slug', $key)->orWhere('id', $key)->pluck('id')
            : $tenant->runUnscoped(fn () => StoreConnection::query()
                ->where(fn ($q) => $q->where('status', ConnectionStatus::Connected)->orWhere('platform', 'shopify'))
                ->whereHas('shop', fn ($shop) => $shop->where('status', 'active'))
                ->pluck('shop_id'));

        foreach ($shopIds as $shopId) {
            try {
                $run = $sync->handle((string) $shopId);
                if ($run !== null) {
                    $this->line((string) $run->summary());
                }
            } catch (Throwable $e) {
                // One store's trouble never stops the others.
                report($e);
            }
        }

        return self::SUCCESS;
    }
}
