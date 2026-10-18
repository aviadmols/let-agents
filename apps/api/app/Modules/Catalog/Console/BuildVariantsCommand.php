<?php

namespace App\Modules\Catalog\Console;

use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Actions\BuildProductVariants;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Console\Command;
use Throwable;

/**
 * catalog:variants                  every shop, only products without variant rows yet
 * catalog:variants gueta-avigdor    one shop
 * catalog:variants --all            every product with variants built again (after VariantRows changed)
 */
final class BuildVariantsCommand extends Command
{
    protected $signature = 'catalog:variants {shop? : shop slug or ID} {--all : build every product again, not only those without rows}';

    protected $description = 'Build the variant rows (colour, pattern, size, stock) of products from their feed records. Code only.';

    public function handle(BuildProductVariants $build, TenantContext $tenant): int
    {
        $key = (string) $this->argument('shop');
        $shopIds = $key !== ''
            ? Shop::query()->where('slug', $key)->orWhere('id', $key)->pluck('id')
            : Shop::query()->pluck('id');

        foreach ($shopIds as $shopId) {
            try {
                $built = $tenant->run((string) $shopId, fn (): int => $this->option('all') ? $build->all() : $build->missing());
                $this->line("{$shopId}: {$built}");
            } catch (Throwable $e) {
                // One store's trouble never stops the others.
                report($e);
            }
        }

        return self::SUCCESS;
    }
}
