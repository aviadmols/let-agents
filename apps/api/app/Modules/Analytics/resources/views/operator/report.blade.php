<x-filament-panels::page>
    @php($report = $this->report())
    @php($pct = fn ($v) => $v === null ? '–' : number_format($v * 100, 1).'%')

    <div class="la-toolbar">
        @if ($this->picksShop())
            <select wire:model.live="shop" class="la-select" aria-label="{{ __('analytics::analytics.shop') }}" style="min-width:220px">
                @foreach ($this->shops() as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        @endif
        <select wire:model.live="days" class="la-select" aria-label="{{ __('analytics::analytics.period') }}">
            @foreach (\App\Modules\Analytics\Actions\BuildShopReport::PERIODS as $period)
                <option value="{{ $period }}">{{ __('analytics::analytics.last_days', ['days' => $period]) }}</option>
            @endforeach
        </select>
    </div>

    @if ($report === null)
        <x-filament::section>{{ __('analytics::analytics.no_shop') }}</x-filament::section>
    @else
        @php($t = $report['totals'])
        @php($money = ['ILS' => '₪', 'USD' => '$', 'EUR' => '€', 'GBP' => '£'][$report['currency']] ?? $report['currency'].' ')

        {{-- The summary row: one tile per total, above the charts. --}}
        <div class="la-stats" data-analytics-stats>
            @foreach ([
                'page_views' => [number_format($t['page_views']), null],
                'visitors' => [number_format($t['visitors']), null],
                'impressions' => [number_format($t['impressions']), null],
                'opens' => [number_format($t['opens']), $pct($t['open_rate'])],
                'clicks' => [number_format($t['clicks']), null],
                'widget_add_to_cart' => [number_format($t['widget_add_to_cart']), null],
                'orders' => [number_format($t['orders']), null],
                'assisted_orders' => [number_format($t['assisted_orders']), null],
                'attributed_revenue' => [$money.number_format($t['attributed_revenue'], 2), null],
            ] as $key => [$value, $note])
                <div class="la-stat">
                    <div class="la-stat-label">{{ __("analytics::analytics.totals.{$key}") }}</div>
                    <div class="la-stat-value"><bdi>{{ $value }}@if ($note) <small>· {{ $note }}</small>@endif</bdi></div>
                </div>
            @endforeach
        </div>

        @if ($t['preview_events'] > 0)
            <p class="la-note">{{ __('analytics::analytics.preview_note', ['count' => number_format($t['preview_events'])]) }}</p>
        @endif

        <div class="la-charts" data-analytics-charts>
            @foreach ($this->charts($report) as $name => $chart)
                <div @class(['la-wide' => $chart['wide']]) data-chart="{{ $name }}">
                    @livewire(\App\Modules\Analytics\Filament\Widgets\ReportChart::NAME, [
                        'kind' => $chart['kind'],
                        'horizontal' => $chart['horizontal'],
                        'title' => $chart['title'],
                        'subtitle' => $chart['subtitle'],
                        'prefix' => $chart['prefix'],
                        'labels' => $chart['labels'],
                        'series' => $chart['series'],
                    ], key('analytics-chart-'.$name))
                </div>
            @endforeach
        </div>

        <x-filament::section :heading="__('analytics::analytics.sections.hot_pages')">
            <div class="la-table-wrap">
                <table class="la-table">
                    <thead><tr>
                        <th>{{ __('analytics::analytics.columns.page') }}</th>
                        <th class="la-num">{{ __('analytics::analytics.columns.views') }}</th>
                        <th class="la-num">{{ __('analytics::analytics.columns.impressions') }}</th>
                        <th class="la-num">{{ __('analytics::analytics.columns.opens') }}</th>
                        <th class="la-num">{{ __('analytics::analytics.columns.add_to_cart') }}</th>
                    </tr></thead>
                    <tbody>
                    @forelse ($report['hot_pages'] as $page)
                        <tr>
                            <td>{{ $page['title'] }}</td>
                            <td class="la-num">{{ number_format($page['views']) }}</td>
                            <td class="la-num">{{ number_format($page['impressions']) }}</td>
                            <td class="la-num">{{ number_format($page['opens']) }}</td>
                            <td class="la-num">{{ number_format($page['add_to_cart']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="la-note">{{ __('analytics::analytics.empty') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        <x-filament::section :heading="__('analytics::analytics.sections.top_products')">
            <div class="la-table-wrap">
                <table class="la-table">
                    <thead><tr>
                        <th>{{ __('analytics::analytics.columns.product') }}</th>
                        <th class="la-num">{{ __('analytics::analytics.columns.add_to_cart') }}</th>
                        <th class="la-num">{{ __('analytics::analytics.columns.purchased') }}</th>
                    </tr></thead>
                    <tbody>
                    @forelse ($report['top_products'] as $product)
                        <tr>
                            <td>{{ $product['title'] }}</td>
                            <td class="la-num">{{ number_format($product['add_to_cart']) }}</td>
                            <td class="la-num">{{ number_format($product['purchased']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="la-note">{{ __('analytics::analytics.empty') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
