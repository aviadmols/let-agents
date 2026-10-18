<?php

namespace App\Modules\Catalog\Listeners;

use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Actions\BuildProductVariants;
use App\Modules\Catalog\Events\CatalogProductsChanged;
use App\Modules\Catalog\Events\ColourNamesPlaced;

/**
 * Colour names were placed: the variants named so are read again, and what reads products (the
 * search) follows, as it does when the store changes them.
 */
final class RebuildNamedVariants
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly BuildProductVariants $variants,
    ) {}

    public function handle(ColourNamesPlaced $event): void
    {
        $changed = $this->tenant->run($event->shopId, fn (): array => $this->variants->named($event->names));

        if ($changed !== []) {
            event(new CatalogProductsChanged($event->shopId, $changed));
        }
    }
}
