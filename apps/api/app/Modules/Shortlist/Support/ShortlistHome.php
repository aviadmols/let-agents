<?php

namespace App\Modules\Shortlist\Support;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Modules\Admin\Contracts\HomeSource;
use App\Modules\Admin\Contracts\HomeStatus;
use App\Modules\Admin\Contracts\ModuleFigure;
use App\Modules\Admin\Contracts\ShopNotice;
use App\Modules\Shortlist\Filament\Merchant\Pages\Compare;
use App\Modules\Shortlist\Filament\Merchant\Pages\Wishlist;
use App\Modules\Shortlist\Models\ShortlistCount;
use App\Modules\Shortlist\Models\ShortlistWishlist;
use App\Modules\Tenancy\Models\Shop;
use Carbon\CarbonInterface;

/**
 * The wishlist and the compare box on the shop's home: on or off, what is not set up yet, and how
 * much shoppers used them. Asked inside the shop.
 */
final class ShortlistHome implements HomeSource
{
    public function statuses(Shop $shop): array
    {
        $wishlist = Features::enabled('shortlist.wishlist', $shop->id);
        $compare = Features::enabled('shortlist.compare', $shop->id);

        return [
            new HomeStatus(
                key: 'wishlist',
                name: __('shortlist::home.wishlist.name'),
                state: $wishlist ? HomeStatus::ON : HomeStatus::OFF,
                note: __($wishlist ? 'shortlist::home.wishlist.on' : 'shortlist::home.wishlist.off'),
                url: Wishlist::getUrl(panel: 'merchant', tenant: $shop),
                action: __($wishlist ? 'shortlist::home.open' : 'shortlist::home.turn_on'),
                order: 40,
            ),
            new HomeStatus(
                key: 'compare',
                name: __('shortlist::home.compare.name'),
                state: $compare ? HomeStatus::ON : HomeStatus::OFF,
                note: __($compare ? 'shortlist::home.compare.on' : 'shortlist::home.compare.off'),
                url: Compare::getUrl(panel: 'merchant', tenant: $shop),
                action: __($compare ? 'shortlist::home.open' : 'shortlist::home.turn_on'),
                order: 50,
            ),
        ];
    }

    public function notices(Shop $shop): array
    {
        $wishlist = Wishlist::getUrl(panel: 'merchant', tenant: $shop);

        if (! Features::enabled('shortlist.wishlist', $shop->id)) {
            return [new ShopNotice(
                key: 'shortlist.wishlist_off',
                kind: ShopNotice::SETUP,
                tone: 'warn',
                title: __('shortlist::home.wishlist_off.title'),
                body: __('shortlist::home.wishlist_off.body'),
                source: __('shortlist::home.wishlist.name'),
                url: $wishlist,
                action: __('shortlist::home.turn_on'),
                weight: 60,
            )];
        }

        if (! Settings::get('shortlist.wishlist_header', $shop->id) && trim((string) Settings::get('shortlist.wishlist_place', $shop->id)) === '') {
            return [new ShopNotice(
                key: 'shortlist.no_header_heart',
                kind: ShopNotice::SETUP,
                tone: 'warn',
                title: __('shortlist::home.no_header_heart.title'),
                body: __('shortlist::home.no_header_heart.body'),
                source: __('shortlist::home.wishlist.name'),
                url: $wishlist,
                action: __('shortlist::home.set_up'),
                weight: 45,
            )];
        }

        return [];
    }

    public function figures(Shop $shop, CarbonInterface $since, CarbonInterface $until, CarbonInterface $before): array
    {
        $lists = fn (CarbonInterface $from, CarbonInterface $to): int => ShortlistWishlist::query()->where('updated_at', '>=', $from)->where('updated_at', '<', $to)->count();
        $compares = fn (CarbonInterface $from, CarbonInterface $to): int => (int) ShortlistCount::query()->where('kind', ShortlistCount::COMPARE)->where('item', '')
            ->whereDate('day', '>=', $from->toDateString())->whereDate('day', '<', $to->toDateString())->sum('n');

        $figures = [];

        if (Features::enabled('shortlist.wishlist', $shop->id)) {
            $figures[] = new ModuleFigure(key: 'wishlist', name: __('shortlist::home.wishlist.name'), uses: $lists($since, $until), previousUses: $lists($before, $since), url: Wishlist::getUrl(panel: 'merchant', tenant: $shop), order: 40);
        }

        if (Features::enabled('shortlist.compare', $shop->id)) {
            $figures[] = new ModuleFigure(key: 'compare', name: __('shortlist::home.compare.name'), uses: $compares($since, $until->copy()->addDay()), previousUses: $compares($before, $since), url: Compare::getUrl(panel: 'merchant', tenant: $shop), order: 50);
        }

        return $figures;
    }
}
