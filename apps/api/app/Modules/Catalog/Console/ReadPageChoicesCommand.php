<?php

namespace App\Modules\Catalog\Console;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Actions\ReadPageChoices;
use App\Modules\Catalog\Jobs\ReadPageChoicesJob;
use App\Modules\Connections\Enums\ConnectionStatus;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Console\Command;
use Throwable;

/**
 * catalog:read-choices                    every shop with catalog.page_choices on
 * catalog:read-choices gueta-avigdor      one shop
 * catalog:read-choices gueta-avigdor --limit=20
 * catalog:read-choices rocks-and-gold --continue   every page due now, part after part (ReadPageChoicesJob)
 */
final class ReadPageChoicesCommand extends Command
{
    protected $signature = 'catalog:read-choices {shop? : shop slug or ID} {--limit= : at most this many pages} {--continue : read every page due now, part after part, on the queue}';

    protected $description = 'Read, slowly, the choices product pages ask for that the feed does not carry (a length, a size).';

    public function handle(ReadPageChoices $read, TenantContext $tenant): int
    {
        $key = (string) $this->argument('shop');
        $limit = $this->option('limit') === null ? null : max(1, (int) $this->option('limit'));
        $shopIds = $key !== ''
            ? Shop::query()->where('slug', $key)->orWhere('id', $key)->pluck('id')
            : $tenant->runUnscoped(fn () => StoreConnection::query()
                // A Shopify store is connected by its install, whatever its status says (as catalog:sync-changes).
                ->where(fn ($q) => $q->where('status', ConnectionStatus::Connected)->orWhere('platform', 'shopify'))
                ->whereHas('shop', fn ($shop) => $shop->where('status', 'active'))
                ->pluck('shop_id'))
                ->filter(fn ($shopId): bool => Features::enabled('catalog.page_choices', (string) $shopId) || Features::enabled('catalog.page_sizes', (string) $shopId));

        foreach ($shopIds as $shopId) {
            if ($this->option('continue')) {
                ReadPageChoicesJob::dispatch((string) $shopId);
                $this->line("{$shopId}: reading on the queue");

                continue;
            }

            try {
                $run = $read->handle((string) $shopId, $limit);
                $this->line($run === null ? "{$shopId}: no change" : (string) $run->summary());
            } catch (Throwable $e) {
                // One store's trouble never stops the others.
                report($e);
            }
        }

        return self::SUCCESS;
    }
}
