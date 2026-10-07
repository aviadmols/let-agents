<?php

namespace App\Modules\Analytics\Filament\Widgets;

use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Reactive;

/**
 * One chart on a report screen, drawn by the Chart.js that ships with Filament.
 *
 * The page that holds it has already built the numbers (BuildShopReport, counts only) and hands
 * them in; the chart never queries and never sees a shop id, so there is nothing in it a visitor
 * could point at another store. When the page's period or shop changes, the series change with
 * it (reactive) and the canvas is redrawn in place.
 *
 * Every chart looks the same: series colors in a fixed order (purple, orange, green, blue, with a
 * dark-mode twin of each read from the panel stylesheet), one y-axis, 2px lines, thin bars with
 * rounded ends, a legend once there are two series or more, and a tooltip on hover.
 *
 * Not in a Filament/{Operator|Merchant}/Widgets folder on purpose: discovered widgets land on the
 * panel dashboard, and this one only makes sense inside a report page.
 */
final class ReportChart extends ChartWidget
{
    public const NAME = 'analytics-report-chart';

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '260px';

    /** line or bar */
    #[Locked]
    public string $kind = 'line';

    /** Bars run sideways, for ranked lists such as the top sections. */
    #[Locked]
    public bool $horizontal = false;

    #[Locked]
    public string $title = '';

    #[Locked]
    public ?string $subtitle = null;

    /** Printed before each value on the axis and in the tooltip, such as "$". */
    #[Locked]
    public string $prefix = '';

    /** @var list<string> */
    #[Reactive]
    public array $labels = [];

    /** @var list<array{label: string, data: list<int|float>}> */
    #[Reactive]
    public array $series = [];

    public function getHeading(): string|Htmlable|null
    {
        return $this->title;
    }

    public function getDescription(): string|Htmlable|null
    {
        return $this->subtitle;
    }

    protected function getType(): string
    {
        return $this->kind === 'bar' ? 'bar' : 'line';
    }

    /** @return array<string, mixed> */
    protected function getData(): array
    {
        return [
            'labels' => array_values($this->labels),
            'datasets' => array_map(fn (array $series): array => [
                'label' => (string) $series['label'],
                'data' => array_values($series['data']),
            ], array_values($this->series)),
        ];
    }

    public function isEmpty(): bool
    {
        foreach ($this->series as $series) {
            foreach ($series['data'] as $value) {
                if ((float) $value !== 0.0) {
                    return false;
                }
            }
        }

        return true;
    }

    public function getEmptyStateHeading(): string|Htmlable
    {
        return __('analytics::analytics.empty');
    }

    public function getEmptyStateIcon(): string|BackedEnum|Htmlable
    {
        return Heroicon::OutlinedChartBar;
    }

    /**
     * Colors are functions of the dataset's position, read from the panel stylesheet's --la-s1..4
     * each time Chart.js draws, so switching to dark mode recolors the series without a reload.
     * Set under `datasets.{line|bar}`, which outranks the single color Filament gives every series.
     */
    protected function getOptions(): RawJs
    {
        $legend = count($this->series) > 1 ? 'true' : 'false';
        $indexAxis = $this->horizontal ? 'y' : 'x';
        $valueAxis = $this->horizontal ? 'x' : 'y';
        $categoryAxis = $this->horizontal ? 'y' : 'x';
        // Single-quoted: the options are printed raw inside a double-quoted x-data attribute.
        $prefix = "'".preg_replace('/[^$€₪£A-Za-z ]/u', '', $this->prefix)."'";
        $decimals = $this->prefix === '' ? 0 : 2;

        return RawJs::make(<<<JS
            (() => {
                const fallback = {
                    light: ['#7e22ce', '#eb6834', '#1baf7a', '#2a78d6'],
                    dark: ['#a855f7', '#d95926', '#199e70', '#3987e5'],
                }
                const color = (context) => {
                    const root = document.documentElement
                    const i = (context.datasetIndex ?? 0) % 4
                    const fromCss = getComputedStyle(root).getPropertyValue('--la-s' + (i + 1)).trim()

                    return fromCss || fallback[root.classList.contains('dark') ? 'dark' : 'light'][i]
                }
                const rtl = document.documentElement.dir === 'rtl'
                const prefix = {$prefix}
                const format = (value) => prefix + Number(value).toLocaleString(undefined, { maximumFractionDigits: {$decimals} })

                return {
                    indexAxis: '{$indexAxis}',
                    interaction: { mode: 'index', intersect: false, axis: '{$categoryAxis}' },
                    datasets: {
                        line: {
                            borderColor: color,
                            backgroundColor: color,
                            pointBackgroundColor: color,
                            pointBorderColor: color,
                            borderWidth: 2,
                            tension: 0.3,
                            pointRadius: 0,
                            pointHoverRadius: 4,
                            fill: false,
                        },
                        bar: {
                            backgroundColor: color,
                            hoverBackgroundColor: color,
                            borderColor: color,
                            borderWidth: 0,
                            borderRadius: 999,
                            borderSkipped: false,
                            maxBarThickness: 10,
                            categoryPercentage: 0.7,
                            barPercentage: 0.9,
                        },
                    },
                    plugins: {
                        legend: {
                            display: {$legend},
                            position: 'bottom',
                            align: 'start',
                            rtl: rtl,
                            labels: { usePointStyle: true, pointStyle: 'circle', boxWidth: 8, boxHeight: 8, padding: 14 },
                        },
                        tooltip: {
                            rtl: rtl,
                            usePointStyle: true,
                            boxWidth: 8,
                            boxHeight: 8,
                            padding: 10,
                            callbacks: {
                                label: (item) => ' ' + item.dataset.label + ': ' + format(item.raw),
                            },
                        },
                    },
                    scales: {
                        {$valueAxis}: {
                            beginAtZero: true,
                            grid: { display: true },
                            ticks: { precision: {$decimals}, maxTicksLimit: 5, callback: (value) => format(value) },
                        },
                        {$categoryAxis}: {
                            grid: { display: false },
                            ticks: { autoSkip: true, maxRotation: 0, maxTicksLimit: 8 },
                        },
                    },
                }
            })()
            JS);
    }
}
