<?php

namespace App\Modules\Widget\Filament\Merchant\Pages;

use App\Core\Tenancy\LocksShopToPanelTenant;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Widget\Filament\Operator\Pages\ProductPage as OperatorProductPage;
use Filament\Facades\Filament;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * One page of this shop as the widget builds it, and the team's say over it. The same screen the
 * operator uses, with the shop fixed to the one in the address: the search looks inside this shop
 * only, never across the platform.
 */
final class ProductPage extends OperatorProductPage
{
    use LocksShopToPanelTenant;

    private const MERCHANT_RESULTS = 12;

    protected static string|UnitEnum|null $navigationGroup = 'on_page';

    protected static ?int $navigationSort = 10;

    /**
     * The operator searches every shop at once; a merchant searches their own, through the tenant
     * scope, so nothing from another shop can come back.
     *
     * @return Collection<int, array{shop_id: string, type: string, external_id: string, title: string, shop: string}>
     */
    public function matches(): Collection
    {
        $term = trim($this->search);
        $shop = Filament::getTenant();

        if ($shop === null || mb_strlen($term) < 2) {
            return collect();
        }

        return app(TenantContext::class)->run((string) $shop->getKey(), function () use ($term, $shop): Collection {
            $products = CatalogProduct::query()->whereNull('removed_at')
                ->where(fn ($q) => $q->where('title', 'like', "%{$term}%")->orWhere('external_id', $term))
                ->orderBy('title')->limit(self::MERCHANT_RESULTS)->get(['id', 'shop_id', 'external_id', 'title'])
                ->map(fn (CatalogProduct $p): array => ['shop_id' => $p->shop_id, 'type' => 'product', 'external_id' => $p->external_id, 'title' => $p->title, 'shop' => (string) $shop->name]);
            $articles = CatalogContent::query()->whereNull('removed_at')
                ->where('title', 'like', "%{$term}%")
                ->orderBy('title')->limit(4)->get(['id', 'shop_id', 'external_id', 'title'])
                ->map(fn (CatalogContent $c): array => ['shop_id' => $c->shop_id, 'type' => 'content', 'external_id' => $c->external_id, 'title' => $c->title, 'shop' => (string) $shop->name]);

            return $products->concat($articles)->values();
        });
    }
}
