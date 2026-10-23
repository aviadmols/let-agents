<?php

namespace App\Modules\Shortlist\Support;

use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Shopify\Contracts\KeepsShopperData;
use App\Modules\Shopify\Events\ShopperDataRequested;
use App\Modules\Shortlist\Models\ShortlistWishlist;

/**
 * A shopper asked the store for what it keeps about them: the wishlist kept in their store account (by the Shopify
 * customer id, as ForgetShopper finds it to erase), the products on it by name. A guest's list is kept by a browser
 * token, with nothing that names anyone. Asked inside the shop.
 */
final class WishlistShopperData implements KeepsShopperData
{
    public function about(ShopperDataRequested $request): array
    {
        if (preg_match('/^[0-9]{1,20}$/', $request->customerId) !== 1) {
            return [];
        }

        $lists = ShortlistWishlist::query()->where('customer_ref', 'shopify:'.$request->customerId)->orderBy('id')->get();

        if ($lists->isEmpty()) {
            return [];
        }

        $ids = $lists->flatMap(fn (ShortlistWishlist $list): array => array_map('strval', (array) $list->items))->unique()->values()->all();
        $titles = CatalogProduct::query()->whereIn('external_id', $ids)->pluck('title', 'external_id')->all();

        return ['wishlist' => $lists->map(fn (ShortlistWishlist $list): array => [
            'created_at' => $list->created_at?->toIso8601String(),
            'updated_at' => $list->updated_at?->toIso8601String(),
            'products' => array_map(fn (string $id): array => ['id' => $id, 'name' => (string) ($titles[$id] ?? '')], array_map('strval', (array) $list->items)),
            'agreed_to_hear_from_the_store' => (bool) $list->marketing_consent,
            // the visit it was last saved from, while kept (shortlist.visit_keep_days)
            'visit' => array_filter([
                'ip' => $list->getAttribute('ip'),
                'device' => $list->getAttribute('device'),
                'browser' => $list->getAttribute('browser'),
                'os' => $list->getAttribute('os'),
                'page' => $list->getAttribute('page'),
                'referrer' => $list->getAttribute('referrer'),
            ], fn ($value): bool => $value !== null && $value !== ''),
        ])->all()];
    }
}
