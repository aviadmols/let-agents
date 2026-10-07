<?php

namespace App\Modules\Analytics\Filament\Operator\Pages;

use App\Core\Tenancy\TenantContext;
use App\Modules\Analytics\Actions\BuildShopReport;
use App\Modules\Runs\Models\Run;
use App\Modules\Tenancy\Models\Shop;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * The same report a store sees in its plugin, for any shop: a row of totals, then charts per day
 * and by section, then the hot pages and products. The operator also sees what the shop cost in
 * model calls per day; the merchant screen that extends this one never does.
 */
class ShopAnalytics extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'analytics';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'analytics';

    protected string $view = 'analytics::operator.report';

    #[Url]
    public ?string $shop = null;

    #[Url]
    public int $days = 30;

    public static function getNavigationLabel(): string
    {
        return __('analytics::analytics.title');
    }

    public function getTitle(): string
    {
        return __('analytics::analytics.title');
    }

    public function mount(): void
    {
        // The shop the panel is inside, so every screen agrees; the first by name when it is
        // looking across every shop.
        $this->shop ??= app(TenantContext::class)->id() ?? Shop::query()->orderBy('name')->value('id');
    }

    /** False in the merchant panel, where the shop is the one in the address and cannot change. */
    public function picksShop(): bool
    {
        return true;
    }

    /** Model costs are the operator's business. The merchant screen turns this off. */
    public function showsCosts(): bool
    {
        return true;
    }

    /** @return array<string, string> */
    public function shops(): array
    {
        return Shop::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    /** @return array<string, mixed>|null */
    public function report(): ?array
    {
        return $this->shop === null ? null : app(BuildShopReport::class)->handle($this->shop, $this->days);
    }

    /**
     * What each chart on the screen draws, from the report already built for this render.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, array{kind: string, horizontal: bool, title: string, subtitle: string, prefix: string, wide: bool, labels: list<string>, series: list<array{label: string, data: list<int|float>}>}>
     */
    public function charts(array $report): array
    {
        $daily = $report['daily'];
        $labels = array_map(fn (array $day): string => Carbon::parse($day['date'])->format('j.n'), $daily);
        $column = fn (string $key): array => array_map(fn (array $day): int => (int) ($day[$key] ?? 0), $daily);
        $label = fn (string $key): string => __("analytics::analytics.{$key}");

        $charts = [
            'traffic' => $this->chart('line', 'traffic', $labels, [
                [$label('totals.page_views'), $column('page_views')],
                [$label('totals.impressions'), $column('impressions')],
            ]),
            'engagement' => $this->chart('line', 'engagement', $labels, [
                [$label('totals.opens'), $column('opens')],
                [$label('totals.clicks'), $column('clicks')],
                [$label('totals.widget_add_to_cart'), $column('add_to_cart')],
            ]),
            'orders' => $this->chart('bar', 'orders', $labels, [
                [$label('totals.orders'), $column('orders')],
                [$label('totals.assisted_orders'), $column('assisted_orders')],
            ]),
        ];

        $sections = array_slice($report['hot_models'], 0, 8);
        $charts['sections'] = $this->chart('bar', 'sections', array_map(fn (array $m): string => $this->sectionName($m['model']), $sections), [
            [$label('columns.opens'), array_column($sections, 'opens')],
            [$label('columns.clicks'), array_column($sections, 'clicks')],
            [$label('columns.add_to_cart'), array_column($sections, 'add_to_cart')],
        ], horizontal: true);

        if ($this->showsCosts()) {
            $charts['cost'] = $this->chart('bar', 'cost', $labels, [
                [$label('charts.cost_series'), $this->costByDay($daily)],
            ], prefix: '$', wide: true);
        }

        return $charts;
    }

    /** A section's name as the merchant knows it, never the internal key when a name exists. */
    public function sectionName(string $model): string
    {
        return Lang::has("analytics::analytics.models.{$model}") ? __("analytics::analytics.models.{$model}") : $model;
    }

    /**
     * Model spend for this shop per day, from the runs log. Operator screens only.
     *
     * @param  list<array{date: string}>  $daily
     * @return list<float>
     */
    protected function costByDay(array $daily): array
    {
        if ($this->shop === null || $daily === []) {
            return [];
        }

        $spent = Run::query()
            ->where('shop_id', $this->shop)
            ->whereNotNull('cost_usd')
            ->whereBetween('started_at', [Carbon::parse($daily[0]['date'])->startOfDay(), Carbon::parse($daily[array_key_last($daily)]['date'])->endOfDay()])
            ->select(DB::raw('DATE(started_at) as day'), DB::raw('sum(cost_usd) as cost'))
            ->groupBy('day')
            ->pluck('cost', 'day')
            ->mapWithKeys(fn ($cost, $day): array => [substr((string) $day, 0, 10) => round((float) $cost, 4)]);

        return array_map(fn (array $day): float => (float) ($spent[$day['date']] ?? 0), $daily);
    }

    /**
     * @param  list<string>  $labels
     * @param  list<array{0: string, 1: list<int|float>}>  $series
     * @return array{kind: string, horizontal: bool, title: string, subtitle: string, prefix: string, wide: bool, labels: list<string>, series: list<array{label: string, data: list<int|float>}>}
     */
    private function chart(string $kind, string $key, array $labels, array $series, bool $horizontal = false, string $prefix = '', bool $wide = false): array
    {
        return [
            'kind' => $kind,
            'horizontal' => $horizontal,
            'title' => __("analytics::analytics.charts.{$key}"),
            'subtitle' => __("analytics::analytics.charts.{$key}_sub"),
            'prefix' => $prefix,
            'wide' => $wide,
            'labels' => array_values($labels),
            'series' => array_map(fn (array $s): array => ['label' => $s[0], 'data' => array_values($s[1])], $series),
        ];
    }
}
