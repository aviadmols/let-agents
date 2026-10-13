<?php

namespace App\Modules\Widget\Support;

use App\Core\Facades\Features;
use App\Modules\Admin\Contracts\HomeSource;
use App\Modules\Admin\Contracts\HomeStatus;
use App\Modules\Admin\Contracts\ModuleFigure;
use App\Modules\Analytics\Models\AnalyticsEvent;
use App\Modules\Analytics\Models\AnalyticsOrder;
use App\Modules\Tenancy\Models\Shop;
use App\Modules\Widget\Filament\Merchant\Pages\ProductPage;
use Carbon\CarbonInterface;

/**
 * The block under a product's add-to-cart on the shop's home: on or off, how often shoppers opened
 * or clicked it, what went to the cart from it and what was bought. Asked inside the shop.
 */
final class WidgetHome implements HomeSource
{
    public function statuses(Shop $shop): array
    {
        $on = Features::enabled('widget.on_products', $shop->id) || Features::enabled('widget.on_content', $shop->id);

        return [new HomeStatus(
            key: 'widget',
            name: __('widget::home.name'),
            state: $on ? HomeStatus::ON : HomeStatus::OFF,
            note: __($on ? 'widget::home.on' : 'widget::home.off'),
            url: ProductPage::getUrl(panel: 'merchant', tenant: $shop),
            action: __('widget::home.open'),
            order: 60,
        )];
    }

    public function notices(Shop $shop): array
    {
        return [];
    }

    public function figures(Shop $shop, CarbonInterface $since, CarbonInterface $until, CarbonInterface $before): array
    {
        $events = fn (CarbonInterface $from, CarbonInterface $to) => AnalyticsEvent::query()->where('occurred_at', '>=', $from)->where('occurred_at', '<', $to)->where(fn ($q) => $q->whereNull('source')->orWhere('source', '!=', AnalyticsEvent::SOURCE_SEARCH));
        $uses = fn (CarbonInterface $from, CarbonInterface $to): int => $events($from, $to)->whereIn('type', ['open', 'click'])->count();
        $orders = AnalyticsOrder::query()->where('source', AnalyticsOrder::SOURCE_LIVE)->where('ordered_at', '>=', $since)->where('ordered_at', '<', $until)->where('attributed_total', '>', 0);

        return [new ModuleFigure(
            key: 'widget',
            name: __('widget::home.name'),
            uses: $uses($since, $until),
            previousUses: $uses($before, $since),
            adds: $events($since, $until)->where('type', 'add_to_cart')->where('source', 'widget')->where('result', 'added')->count(),
            orders: (clone $orders)->count(),
            revenue: round((float) (clone $orders)->sum('attributed_total'), 2),
            url: ProductPage::getUrl(panel: 'merchant', tenant: $shop),
            order: 60,
        )];
    }
}
