<?php

namespace App\Modules\Catalog\Console;

use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Actions\SyncStock;
use App\Modules\Connections\Enums\ConnectionStatus;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Console\Command;
use Throwable;

/**
 * catalog:stock                 every connected shop whose check is due: what can be bought now, nothing else
 * catalog:stock rocks-and-gold  one shop, now, whenever it was last checked
 */
final class SyncStockCommand extends Command
{
    protected $signature = 'catalog:stock {shop? : shop slug or ID}';

    protected $description = 'Read only what each store has in stock now, as often as the shop asks (catalog.stock_every_hours).';

    public function handle(SyncStock $stock, TenantContext $tenant): int
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
                $run = $stock->handle((string) $shopId, $key !== '' ? RunTrigger::Manual : RunTrigger::Schedule, now: $key !== '');
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
