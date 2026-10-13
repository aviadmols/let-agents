<?php

namespace App\Modules\Ai\Support;

use App\Core\Facades\Settings;
use App\Modules\Admin\Contracts\HomeSource;
use App\Modules\Admin\Contracts\ShopNotice;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Tenancy\Models\Shop;
use Carbon\CarbonInterface;

/** The month's allowance on the shop's home, once it is near its end: a share, never an amount. */
final class AllowanceHome implements HomeSource
{
    public function __construct(private readonly SpendGuard $guard) {}

    public function statuses(Shop $shop): array
    {
        return [];
    }

    public function notices(Shop $shop): array
    {
        $share = UsageStrip::share($this->guard->spentThisMonthFor($shop->id), $this->guard->capFor($shop->id));

        if ($share['tone'] === '') {
            return [];
        }

        return [new ShopNotice(
            key: 'ai.allowance.'.now()->format('Y-m'),
            kind: ShopNotice::TODO,
            tone: $share['tone'] === 'bad' ? 'bad' : 'warn',
            title: __('ai::budget.home.title', ['percent' => min($share['percent'], 100)]),
            body: __($share['tone'] === 'bad'
                ? (Settings::get('ai.shop_cap_stops_shoppers', $shop->id) ? 'ai::budget.strip.used_up_all' : 'ai::budget.strip.used_up')
                : 'ai::budget.home.near'),
            source: __('ai::budget.strip.title'),
            at: now(),
            weight: 95,
        )];
    }

    public function figures(Shop $shop, CarbonInterface $since, CarbonInterface $until, CarbonInterface $before): array
    {
        return [];
    }
}
