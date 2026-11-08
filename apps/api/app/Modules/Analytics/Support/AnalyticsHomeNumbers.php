<?php

namespace App\Modules\Analytics\Support;

use App\Modules\Admin\Contracts\HomeNumbers;
use App\Modules\Admin\Contracts\ReportSeries;
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
            'currency' => (string) (AnalyticsOrder::query()->where('source', AnalyticsOrder::SOURCE_LIVE)->latest('ordered_at')->value('currency') ?? ($shop->currency ?: 'ILS')),
            'visitors' => ['now' => $now['visitors'], 'before' => $then['visitors']],
            'adds' => ['now' => $now['adds'], 'before' => $then['adds']],
            'orders' => ['now' => $now['orders'], 'before' => $then['orders']],
            'revenue' => ['now' => $now['revenue'], 'before' => $then['revenue']],
            'all_orders' => $now['all_orders'],
            'series' => $this->series($since, $until),
        ];
    }

    /**
     * The money and the orders through the system by step of the period (ReportSeries: hours, days or months),
     * for Today's line under the big number.
     *
     * @return array{revenue: array<string, float>, orders: array<string, int>}
     */
    private function series(Carbon $from, Carbon $to): array
    {
        $format = ReportSeries::scale($from, $to)['format'];
        $revenue = [];
        $orders = [];

        AnalyticsOrder::query()->where('source', AnalyticsOrder::SOURCE_LIVE)->where('ordered_at', '>=', $from)->where('ordered_at', '<', $to)
            ->where(fn (Builder $q) => $q->where('assisted', true)->orWhere('search_total', '>', 0))
            ->get(['ordered_at', 'attributed_total', 'search_total'])
            ->each(function (AnalyticsOrder $order) use ($format, &$revenue, &$orders): void {
                $key = ReportSeries::key($order->ordered_at, $format);
                $revenue[$key] = round(($revenue[$key] ?? 0) + (float) $order->attributed_total + (float) $order->search_total, 2);
                $orders[$key] = ($orders[$key] ?? 0) + 1;
            });

        return ['revenue' => $revenue, 'orders' => $orders];
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
