<?php

namespace App\Modules\Analytics\Support;

use App\Modules\Admin\Contracts\HomeNumbers;
use App\Modules\Analytics\Models\AnalyticsEvent;
use App\Modules\Analytics\Models\AnalyticsOrder;
use App\Modules\Tenancy\Models\Shop;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The home's row of numbers, this period against the one before: visitors, what went to the cart
 * through the system (the widget's and the search's), the orders it had a part in and the money in
 * them that it brought. Asked inside the shop. Counts only.
 */
final class AnalyticsHomeNumbers implements HomeNumbers
{
    public function numbers(Shop $shop, int $days, ?CarbonInterface $since = null, ?CarbonInterface $until = null): array
    {
        $until = $until === null ? now() : Carbon::instance($until);
        $since = $since === null ? $until->copy()->subDays($days)->startOfDay() : Carbon::instance($since);
        $before = $since->copy()->sub($since->diff($until));

        $now = $this->window($since, $until);
        $then = $this->window($before, $since);

        return [
            'currency' => (string) (AnalyticsOrder::query()->where('source', AnalyticsOrder::SOURCE_LIVE)->latest('ordered_at')->value('currency') ?? 'ILS'),
            'visitors' => ['now' => $now['visitors'], 'before' => $then['visitors']],
            'adds' => ['now' => $now['adds'], 'before' => $then['adds']],
            'orders' => ['now' => $now['orders'], 'before' => $then['orders']],
            'revenue' => ['now' => $now['revenue'], 'before' => $then['revenue']],
            'all_orders' => $now['all_orders'],
        ];
    }

    /** @return array{visitors: int, adds: int, orders: int, revenue: float, all_orders: int} */
    private function window(Carbon $from, Carbon $to): array
    {
        $events = fn (): Builder => AnalyticsEvent::query()->where('occurred_at', '>=', $from)->where('occurred_at', '<', $to);
        $orders = fn (): Builder => AnalyticsOrder::query()->where('source', AnalyticsOrder::SOURCE_LIVE)->where('ordered_at', '>=', $from)->where('ordered_at', '<', $to);
        $through = fn (): Builder => $orders()->where(fn (Builder $q) => $q->where('assisted', true)->orWhere('search_total', '>', 0));

        return [
            'visitors' => $events()->where('type', 'page_view')->distinct()->count('visitor_hash'),
            'adds' => $events()->where('type', 'add_to_cart')->where('result', 'added')->whereIn('source', ['widget', AnalyticsEvent::SOURCE_SEARCH])->count(),
            'orders' => $through()->count(),
            'revenue' => round((float) $through()->sum(DB::raw('attributed_total + search_total')), 2),
            'all_orders' => $orders()->count(),
        ];
    }
}
