<?php

namespace App\Modules\Catalog\Support;

use App\Modules\Admin\Contracts\HomeSource;
use App\Modules\Admin\Contracts\ShopNotice;
use App\Modules\Catalog\Contracts\StorefrontLock;
use App\Modules\Tenancy\Enums\ShopPlatform;
use App\Modules\Tenancy\Models\Shop;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * A storefront closed to visitors (StorefrontLock: Shopify's password page, a maintenance or
 * coming-soon page), told to the shop in plain words: the scan read the products and pages from the
 * platform instead, and the rest follows once the store opens. Nothing to do, so an insight, not a
 * task; gone with the first page read open. Asked inside the shop.
 */
final class StorefrontHome implements HomeSource
{
    public function statuses(Shop $shop): array
    {
        return [];
    }

    public function notices(Shop $shop): array
    {
        $state = StorefrontLock::state((string) $shop->id);

        if ($state === null || ! StorefrontLock::wide($state['kind'])) {
            return [];
        }

        return [new ShopNotice(
            key: 'catalog.storefront_locked',
            kind: ShopNotice::INSIGHT,
            tone: 'info',
            title: __('catalog::home.locked.title'),
            body: __('catalog::home.locked.body.'.$state['kind'], ['platform' => $shop->platform === ShopPlatform::Shopify ? 'Shopify' : 'WooCommerce']),
            source: __('catalog::home.locked.source'),
            at: Carbon::parse($state['at']),
            weight: 60,
        )];
    }

    public function figures(Shop $shop, CarbonInterface $since, CarbonInterface $until, CarbonInterface $before): array
    {
        return [];
    }
}
