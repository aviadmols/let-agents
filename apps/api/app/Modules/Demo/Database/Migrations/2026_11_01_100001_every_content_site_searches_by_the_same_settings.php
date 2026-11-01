<?php

use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Events\CatalogProductsChanged;
use App\Modules\Demo\Support\ContentSite;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Database\Migrations\Migration;

/**
 * Every content site searches by the same settings (ContentSite::align): what was added after a site was settled is set
 * where the shop has no choice of its own for it. Gil Travel, settled on 27.10, never had the length and tag filters of
 * 28.10. A site that gained a setting has its search index built again (an empty "products changed": nothing is read
 * again, nothing is tagged, only the index).
 */
return new class extends Migration
{
    public function up(): void
    {
        app(TenantContext::class)->runUnscoped(function (): void {
            foreach (Shop::query()->orderBy('id')->get() as $shop) {
                if (ContentSite::is($shop) && ContentSite::align($shop->id)) {
                    event(new CatalogProductsChanged($shop->id, []));
                }
            }
        });
    }

    public function down(): void
    {
        // the settings stay: they are what every content site has, and the team may change them
    }
};
