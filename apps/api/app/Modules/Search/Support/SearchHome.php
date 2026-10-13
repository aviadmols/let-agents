<?php

namespace App\Modules\Search\Support;

use App\Core\Facades\Features;
use App\Modules\Admin\Contracts\HomeSource;
use App\Modules\Admin\Contracts\HomeStatus;
use App\Modules\Admin\Contracts\ModuleFigure;
use App\Modules\Admin\Contracts\ShopNotice;
use App\Modules\Analytics\Models\AnalyticsOrder;
use App\Modules\Search\Filament\Merchant\Pages\PhotoSearches;
use App\Modules\Search\Filament\Merchant\Pages\SearchDisplay;
use App\Modules\Search\Filament\Merchant\Pages\ShopSearches;
use App\Modules\Search\Models\SearchPhotoAsk;
use App\Modules\Search\Models\SearchTerm;
use App\Modules\Tenancy\Models\Shop;
use Carbon\CarbonInterface;

/**
 * The search on the shop's home: is it working on the site (shoppers searched this week), search by
 * photo, the search with no results most worth fixing, and what the search brought. Asked inside the shop.
 */
final class SearchHome implements HomeSource
{
    /** A search that found nothing this many times in a week is worth a card. */
    private const EMPTY_WORTH = 3;

    public function statuses(Shop $shop): array
    {
        $on = Features::enabled('search.storefront', $shop->id);
        $week = (int) SearchTerm::query()->whereDate('day', '>=', now()->subDays(7)->toDateString())->sum('searches');
        $display = SearchDisplay::getUrl(panel: 'merchant', tenant: $shop);

        $statuses = [
            new HomeStatus(
                key: 'search',
                name: __('search::home.search.name'),
                state: ! $on ? HomeStatus::OFF : ($week > 0 ? HomeStatus::ON : HomeStatus::SETUP),
                note: ! $on ? __('search::home.search.off') : ($week > 0 ? __('search::home.search.on', ['count' => number_format($week), 'products' => number_format(SearchScanDetails::products())]) : __('search::home.search.setup', ['products' => number_format(SearchScanDetails::products())])),
                url: $display,
                action: $on && $week === 0 ? __('search::home.search.connect') : __('search::home.open'),
                order: 10,
            ),
        ];

        $photos = Features::enabled('search.photos', $shop->id);
        $statuses[] = new HomeStatus(
            key: 'photos',
            name: __('search::home.photos.name'),
            state: $photos ? HomeStatus::ON : HomeStatus::OFF,
            note: __($photos ? 'search::home.photos.on' : 'search::home.photos.off'),
            url: $display,
            action: __('search::home.open'),
            order: 30,
        );

        return $statuses;
    }

    public function notices(Shop $shop): array
    {
        $empty = SearchTerm::query()
            ->whereDate('day', '>=', now()->subDays(7)->toDateString())
            ->selectRaw('query, SUM(searches) as searches, SUM(empty) as empty')
            ->groupBy('query')
            ->havingRaw('SUM(empty) >= ?', [self::EMPTY_WORTH])
            ->orderByDesc('empty')->orderBy('query')
            ->first();

        if ($empty === null) {
            return [];
        }

        return [new ShopNotice(
            key: 'search.empty.'.md5((string) $empty->query),
            kind: ShopNotice::INSIGHT,
            tone: 'tip',
            title: __('search::home.empty.title', ['query' => $empty->query, 'searches' => (int) $empty->searches, 'empty' => (int) $empty->empty]),
            body: __('search::home.empty.body'),
            source: __('search::home.search.name'),
            at: now(),
            url: ShopSearches::getUrl(panel: 'merchant', tenant: $shop),
            action: __('search::home.empty.action'),
            weight: 40,
        )];
    }

    public function figures(Shop $shop, CarbonInterface $since, CarbonInterface $until, CarbonInterface $before): array
    {
        $terms = fn (CarbonInterface $from, CarbonInterface $to) => SearchTerm::query()->whereDate('day', '>=', $from->toDateString())->whereDate('day', '<', $to->toDateString());
        $now = $terms($since, $until->copy()->addDay())->selectRaw('COALESCE(SUM(searches),0) as searches, COALESCE(SUM(added),0) as added')->first();
        $orders = AnalyticsOrder::query()->where('source', AnalyticsOrder::SOURCE_LIVE)->where('ordered_at', '>=', $since)->where('ordered_at', '<', $until)->where('search_total', '>', 0);
        $photos = fn (CarbonInterface $from, CarbonInterface $to): int => SearchPhotoAsk::query()->where('created_at', '>=', $from)->where('created_at', '<', $to)->count();

        return [
            new ModuleFigure(
                key: 'search',
                name: __('search::home.search.name'),
                uses: (int) $now->searches,
                previousUses: (int) $terms($before, $since)->sum('searches'),
                adds: (int) $now->added,
                orders: (clone $orders)->count(),
                revenue: round((float) (clone $orders)->sum('search_total'), 2),
                url: ShopSearches::getUrl(panel: 'merchant', tenant: $shop),
                order: 10,
            ),
            new ModuleFigure(
                key: 'photos',
                name: __('search::home.photos.name'),
                uses: $photos($since, $until),
                previousUses: $photos($before, $since),
                url: PhotoSearches::getUrl(panel: 'merchant', tenant: $shop),
                order: 30,
            ),
        ];
    }
}
