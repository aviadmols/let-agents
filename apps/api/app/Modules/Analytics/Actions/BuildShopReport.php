<?php

namespace App\Modules\Analytics\Actions;

use App\Core\Tenancy\TenantContext;
use App\Modules\Analytics\Models\AnalyticsEvent;
use App\Modules\Analytics\Models\AnalyticsOrder;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What happened in a shop over a period, in one structure that the plugin's report page, the
 * operator panel and the merchant panel all render:
 *
 *   totals        views, visitors, widget shown, opened, clicks, add to cart, orders, revenue
 *   daily         the same per day, for the trend chart
 *   hot_pages     the most viewed pages, with how the widget did on each
 *   hot_models    each kind of suggestion: shown, opened, clicked, added to cart
 *   top_products  products added to the cart from the widget, and how many were bought
 *
 * Counts only, no visitor-level data leaves this class.
 */
final class BuildShopReport
{
    public const PERIODS = [7, 30, 90];

    private const TOP = 10;

    public function __construct(private readonly TenantContext $tenant) {}

    /** @return array<string, mixed> */
    public function handle(string $shopId, int $days): array
    {
        $days = in_array($days, self::PERIODS, true) ? $days : 30;
        $until = now();
        $since = $until->copy()->subDays($days)->startOfDay();

        return $this->tenant->run($shopId, function () use ($days, $since, $until): array {
            $events = fn (): Builder => AnalyticsEvent::query()->whereBetween('occurred_at', [$since, $until]);
            // Past orders sent once from the store's history are for learning what sells together,
            // not for a report of what Let Agents did: Let Agents was not there when they were placed.
            $orders = fn (): Builder => AnalyticsOrder::query()->where('source', AnalyticsOrder::SOURCE_LIVE)->whereBetween('ordered_at', [$since, $until]);

            $counts = $events()->select('type', DB::raw('count(*) as n'))->groupBy('type')->pluck('n', 'type');
            $widgetAdds = $events()->where('type', 'add_to_cart')->where('source', 'widget')->where('result', 'added')->count();
            $impressions = (int) ($counts['exposure'] ?? 0);
            $opens = (int) ($counts['open'] ?? 0);
            $orderCount = $orders()->count();
            $visitors = $events()->where('type', 'page_view')->distinct()->count('visitor_hash');

            return [
                'period' => ['days' => $days, 'since' => $since->toDateString(), 'until' => $until->toDateString()],
                'currency' => $orders()->value('currency') ?? 'ILS',
                'totals' => [
                    'page_views' => (int) ($counts['page_view'] ?? 0),
                    'visitors' => $visitors,
                    'impressions' => $impressions,
                    'opens' => $opens,
                    'open_rate' => $impressions > 0 ? round($opens / $impressions, 4) : null,
                    'clicks' => (int) ($counts['click'] ?? 0),
                    'widget_add_to_cart' => $widgetAdds,
                    'orders' => $orderCount,
                    'revenue' => round((float) $orders()->sum('total'), 2),
                    'assisted_orders' => $orders()->where('assisted', true)->count(),
                    'attributed_revenue' => round((float) $orders()->sum('attributed_total'), 2),
                    'conversion_rate' => $visitors > 0 ? round($orderCount / $visitors, 4) : null,
                    'preview_events' => $events()->where('preview', true)->count(),
                ],
                'daily' => $this->daily($events(), $orders(), $since, $until),
                'hot_pages' => $this->hotPages($events()),
                'hot_models' => $this->hotModels($events()),
                'top_products' => $this->topProducts($events(), $orders()),
            ];
        });
    }

    /**
     * @param  Builder<AnalyticsEvent>  $events
     * @param  Builder<AnalyticsOrder>  $orders
     * @return list<array{date: string, page_views: int, impressions: int, opens: int, clicks: int, add_to_cart: int, orders: int, assisted_orders: int}>
     */
    private function daily(Builder $events, Builder $orders, Carbon $since, Carbon $until): array
    {
        // Adds from the store's own button are counted too (for product popularity); this report
        // is about what Let Agents did, so the line counts the widget's own adds only.
        $byDay = (clone $events)
            ->select(DB::raw('DATE(occurred_at) as day'), 'type', DB::raw('count(*) as n'))
            ->whereIn('type', ['page_view', 'exposure', 'open', 'click', 'add_to_cart'])
            ->where(fn (Builder $q) => $q->where('type', '!=', 'add_to_cart')
                ->orWhere(fn (Builder $add) => $add->where('source', 'widget')->where('result', 'added')))
            ->groupBy('day', 'type')
            ->get()
            ->groupBy(fn ($row): string => substr((string) $row->day, 0, 10));

        $ordersByDay = (clone $orders)
            ->select(DB::raw('DATE(ordered_at) as day'), DB::raw('count(*) as n'), DB::raw('sum(case when assisted then 1 else 0 end) as assisted'))
            ->groupBy('day')
            ->get()
            ->keyBy(fn ($row): string => substr((string) $row->day, 0, 10));

        $days = [];
        for ($day = $since->copy(); $day->lte($until); $day->addDay()) {
            $key = $day->toDateString();
            $rows = $byDay->get($key, collect())->pluck('n', 'type');
            $days[] = [
                'date' => $key,
                'page_views' => (int) ($rows['page_view'] ?? 0),
                'impressions' => (int) ($rows['exposure'] ?? 0),
                'opens' => (int) ($rows['open'] ?? 0),
                'clicks' => (int) ($rows['click'] ?? 0),
                'add_to_cart' => (int) ($rows['add_to_cart'] ?? 0),
                'orders' => (int) ($ordersByDay->get($key)?->n ?? 0),
                'assisted_orders' => (int) ($ordersByDay->get($key)?->assisted ?? 0),
            ];
        }

        return $days;
    }

    /**
     * @param  Builder<AnalyticsEvent>  $events
     * @return list<array<string, mixed>>
     */
    private function hotPages(Builder $events): array
    {
        $rows = (clone $events)
            ->select(
                'page_type',
                DB::raw('coalesce(product_external_id, content_external_id, page_path) as ref'),
                DB::raw("sum(case when type = 'page_view' then 1 else 0 end) as views"),
                DB::raw("sum(case when type = 'exposure' then 1 else 0 end) as impressions"),
                DB::raw("sum(case when type = 'open' then 1 else 0 end) as opens"),
                DB::raw("sum(case when type = 'add_to_cart' and source = 'widget' and result = 'added' then 1 else 0 end) as adds"),
            )
            ->whereIn('page_type', ['product', 'content'])
            ->groupBy('page_type', 'ref')
            ->orderByDesc('views')
            ->limit(self::TOP)
            ->get();

        $products = CatalogProduct::query()->whereIn('external_id', $rows->where('page_type', 'product')->pluck('ref'))->get(['external_id', 'title', 'url'])->keyBy('external_id');
        $articles = CatalogContent::query()->whereIn('external_id', $rows->where('page_type', 'content')->pluck('ref'))->get(['external_id', 'title', 'url'])->keyBy('external_id');

        return $rows->map(function ($row) use ($products, $articles): array {
            $subject = $row->page_type === 'product' ? $products->get($row->ref) : $articles->get($row->ref);

            return [
                'page_type' => $row->page_type,
                'id' => (string) $row->ref,
                'title' => $subject?->title ?? (string) $row->ref,
                'url' => $subject?->url,
                'views' => (int) $row->views,
                'impressions' => (int) $row->impressions,
                'opens' => (int) $row->opens,
                'add_to_cart' => (int) $row->adds,
            ];
        })->values()->all();
    }

    /**
     * @param  Builder<AnalyticsEvent>  $events
     * @return list<array<string, mixed>>
     */
    private function hotModels(Builder $events): array
    {
        return (clone $events)
            ->select(
                'model',
                DB::raw("sum(case when type = 'exposure' then 1 else 0 end) as impressions"),
                DB::raw("sum(case when type = 'open' then 1 else 0 end) as opens"),
                DB::raw("sum(case when type = 'click' then 1 else 0 end) as clicks"),
                DB::raw("sum(case when type = 'add_to_cart' and result = 'added' then 1 else 0 end) as adds"),
            )
            ->whereNotNull('model')
            ->groupBy('model')
            ->get()
            ->map(fn ($row): array => [
                'model' => (string) $row->model,
                'impressions' => (int) $row->impressions,
                'opens' => (int) $row->opens,
                'clicks' => (int) $row->clicks,
                'add_to_cart' => (int) $row->adds,
                'engagement_rate' => (int) $row->impressions > 0 ? round(((int) $row->opens + (int) $row->clicks) / (int) $row->impressions, 4) : null,
            ])
            ->sortByDesc(fn (array $m): int => $m['opens'] + $m['clicks'] + $m['add_to_cart'])
            ->values()
            ->all();
    }

    /**
     * @param  Builder<AnalyticsEvent>  $events
     * @param  Builder<AnalyticsOrder>  $orders
     * @return list<array<string, mixed>>
     */
    private function topProducts(Builder $events, Builder $orders): array
    {
        $adds = (clone $events)
            ->where('type', 'add_to_cart')->where('source', 'widget')->where('result', 'added')
            ->whereNotNull('item_external_id')
            ->select('item_external_id', DB::raw('count(*) as n'))
            ->groupBy('item_external_id')
            ->orderByDesc('n')
            ->limit(self::TOP)
            ->pluck('n', 'item_external_id');

        $bought = [];
        foreach ((clone $orders)->whereNotNull('attributed_items')->pluck('attributed_items') as $items) {
            foreach ((array) $items as $id) {
                $bought[(string) $id] = ($bought[(string) $id] ?? 0) + 1;
            }
        }

        $titles = CatalogProduct::query()->whereIn('external_id', $adds->keys())->pluck('title', 'external_id');

        return $adds->map(fn ($n, $id): array => [
            'id' => (string) $id,
            'title' => (string) ($titles[$id] ?? $id),
            'add_to_cart' => (int) $n,
            'purchased' => (int) ($bought[(string) $id] ?? 0),
        ])->values()->all();
    }
}
