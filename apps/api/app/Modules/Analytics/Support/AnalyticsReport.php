<?php

namespace App\Modules\Analytics\Support;

use App\Modules\Admin\Contracts\ReportRow;
use App\Modules\Admin\Contracts\ReportSeries;
use App\Modules\Admin\Contracts\ShopReport;
use App\Modules\Analytics\Models\AnalyticsEvent;
use App\Modules\Analytics\Models\AnalyticsOrder;
use App\Modules\Analytics\Models\AnalyticsVisitStep;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Tenancy\Models\Shop;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Analytics' part of the shop's analytics screen: page views and openings of Lets (from the visits),
 * adds to the cart by where they came from, the orders Lets had a part in and their money, adds by
 * day, and the products added most. Counts only.
 */
final class AnalyticsReport implements ShopReport
{
    public function figures(Shop $shop, CarbonInterface $since, CarbonInterface $until): array
    {
        $steps = fn (string $kind): int => AnalyticsVisitStep::query()->where('kind', $kind)->where('at', '>=', $since)->where('at', '<', $until)
            ->whereHas('visit', fn (Builder $q) => $q->where('preview', false))->count();
        $events = fn (): Builder => AnalyticsEvent::query()->where('preview', false)->where('occurred_at', '>=', $since)->where('occurred_at', '<', $until);
        $adds = $events()->where('type', 'add_to_cart')->where('result', 'added')->selectRaw('source, count(*) as n')->groupBy('source')->pluck('n', 'source')->map(fn ($n): int => (int) $n);
        $orders = AnalyticsOrder::query()->where('source', AnalyticsOrder::SOURCE_LIVE)->where('ordered_at', '>=', $since)->where('ordered_at', '<', $until)
            ->where(fn (Builder $q) => $q->where('assisted', true)->orWhere('search_total', '>', 0));

        return [
            // The visit beacon counts every page; before it, the widget counted product and post pages.
            'views' => max($steps('view'), $events()->where('type', 'page_view')->count()),
            'opens' => $steps('open'),
            'adds' => [
                'all' => (int) $adds->sum(),
                'search' => (int) ($adds[AnalyticsEvent::SOURCE_SEARCH] ?? 0),
                'widget' => (int) ($adds['widget'] ?? 0),
                'page' => (int) ($adds['page'] ?? 0),
            ],
            'orders' => [
                'n' => (clone $orders)->count(),
                'revenue' => round((float) (clone $orders)->sum('attributed_total') + (float) (clone $orders)->sum('search_total'), 2),
                'currency' => (string) (AnalyticsOrder::query()->where('source', AnalyticsOrder::SOURCE_LIVE)->latest('ordered_at')->value('currency') ?? $shop->currency ?? 'ILS'),
            ],
            'series' => $this->series($since, $until),
        ];
    }

    public function lists(): array
    {
        return ['adds'];
    }

    public function rows(Shop $shop, string $list, CarbonInterface $since, CarbonInterface $until, int $limit, string $search = '', string $sort = 'value'): array
    {
        $events = fn (): Builder => AnalyticsEvent::query()->where('preview', false)->where('occurred_at', '>=', $since)->where('occurred_at', '<', $until);
        $adds = $events()->where('type', 'add_to_cart')->where('result', 'added')->whereNotNull('item_external_id')
            ->selectRaw('item_external_id as id, count(*) as n')->groupBy('item_external_id')->pluck('n', 'id')->map(fn ($n): int => (int) $n)->all();
        $views = $events()->where('type', 'page_view')->whereIn('product_external_id', array_keys($adds))
            ->selectRaw('product_external_id as id, count(*) as n')->groupBy('product_external_id')->pluck('n', 'id')->map(fn ($n): int => (int) $n)->all();
        $products = CatalogProduct::query()->whereIn('external_id', array_keys($adds))->get(['external_id', 'title', 'image_url', 'price'])->keyBy('external_id');
        $symbol = ['ILS' => '₪', 'USD' => '$', 'EUR' => '€', 'GBP' => '£'][(string) $shop->currency] ?? '';
        $rows = [];

        foreach ($adds as $id => $n) {
            $product = $products[(string) $id] ?? null;
            $title = trim((string) ($product?->title ?? ''));

            if ($title === '' || ($search !== '' && mb_stripos($title, trim($search)) === false)) {
                continue;
            }

            $rows[] = new ReportRow(
                key: (string) $id, title: $title, value: $n, thumb: $product?->image_url,
                sub: implode(' · ', array_filter([
                    $product?->price !== null ? $symbol.number_format((float) $product->price, (float) $product->price >= 100 ? 0 : 2) : null,
                    isset($views[(string) $id]) ? __('analytics::report.views', ['n' => number_format($views[(string) $id])]) : null,
                ])),
            );
        }

        usort($rows, fn (ReportRow $a, ReportRow $b): int => $sort === 'name' ? strcmp($a->title, $b->title) : [$b->value, $a->title] <=> [$a->value, $b->title]);

        return ['rows' => array_slice($rows, 0, $limit), 'total' => count($rows)];
    }

    public function act(Shop $shop, string $list, string $key, string $action, string $value = ''): ?string
    {
        return null;
    }

    /**
     * Openings of Lets and adds to the cart through the period, at the chart's scale (ReportSeries).
     *
     * @return array{opens: array<string, int>, adds: array<string, int>}
     */
    private function series(CarbonInterface $since, CarbonInterface $until): array
    {
        $format = ReportSeries::scale($since, $until)['format'];
        $adds = AnalyticsEvent::query()->where('preview', false)->where('type', 'add_to_cart')->where('result', 'added')
            ->where('occurred_at', '>=', $since)->where('occurred_at', '<', $until)->get(['occurred_at'])
            ->countBy(fn (AnalyticsEvent $e): string => ReportSeries::key($e->occurred_at, $format))->all();
        $opens = AnalyticsVisitStep::query()->where('kind', 'open')->where('at', '>=', $since)->where('at', '<', $until)
            ->whereHas('visit', fn (Builder $q) => $q->where('preview', false))->get(['at'])
            ->countBy(fn (AnalyticsVisitStep $s): string => ReportSeries::key($s->at, $format))->all();

        return ['opens' => $opens, 'adds' => $adds];
    }
}
