<?php

namespace App\Modules\Catalog\Listeners;

use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Actions\BuildProductVariants;
use App\Modules\Catalog\Events\CatalogProductsChanged;
use App\Modules\Catalog\Events\ColourNamesPlaced;

/**
 * Colour names were placed: the variants named so are read again, and what reads products (the
 * search) follows, as it does when the store changes them. What it works with is taken when it runs,
 * never in its constructor: under Octane the event dispatcher builds a listener from the app as it
 * booted, whose TenantContext is not the one the tenant scope reads (Shopify's AnswerShopperData, 11.11).
 */
final class RebuildNamedVariants
{
    public function handle(ColourNamesPlaced $event): void
    {
        $changed = app(TenantContext::class)->run($event->shopId, fn (): array => app(BuildProductVariants::class)->named($event->names));

        if ($changed !== []) {
            event(new CatalogProductsChanged($event->shopId, $changed));
        }
    }
}
