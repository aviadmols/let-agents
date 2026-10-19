<?php

namespace App\Modules\Catalog\Console;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Actions\ReadSitePages;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Console\Command;
use Throwable;

/**
 * catalog:site-pages                  every active shop with catalog.site_pages on
 * catalog:site-pages rocks-and-gold   one shop
 */
final class ReadSitePagesCommand extends Command
{
    protected $signature = 'catalog:site-pages {shop? : shop slug or ID}';

    protected $description = 'Read the pages of the store\'s own site the feed did not bring (its sitemap, its policies) for the assistant and search.';

    public function handle(ReadSitePages $read, TenantContext $tenant): int
    {
        $key = (string) $this->argument('shop');
        $shopIds = $key !== ''
            ? Shop::query()->where('slug', $key)->orWhere('id', $key)->pluck('id')
            : $tenant->runUnscoped(fn () => Shop::query()->where('status', 'active')->pluck('id'))
                ->filter(fn ($shopId): bool => Features::enabled('catalog.site_pages', (string) $shopId));

        foreach ($shopIds as $shopId) {
            try {
                $run = $read->handle((string) $shopId);
                $this->line($run === null ? "{$shopId}: off" : (string) $run->summary());
            } catch (Throwable $e) {
                // One store's trouble never stops the others.
                report($e);
            }
        }

        return self::SUCCESS;
    }
}
