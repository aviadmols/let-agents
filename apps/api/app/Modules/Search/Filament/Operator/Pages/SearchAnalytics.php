<?php

namespace App\Modules\Search\Filament\Operator\Pages;

use App\Core\Tenancy\TenantContext;
use App\Modules\Analytics\Models\AnalyticsOrder;
use App\Modules\Assistant\Models\AssistantSearchAsk;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Search\Actions\CountSearch;
use App\Modules\Search\Models\SearchClick;
use App\Modules\Search\Models\SearchTerm;
use App\Modules\Tenancy\Models\Shop;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * The site search, day after day: how many searches, how many found nothing, how many led to a
 * click, the photos and the questions; and for any day, what was searched.
 */
class SearchAnalytics extends Page
{
    private const TOP = 30;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBarSquare;

    protected static string|UnitEnum|null $navigationGroup = 'analytics';

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'analytics/search';

    protected string $view = 'search::operator.search-analytics';

    #[Url]
    public ?string $shop = null;

    #[Url]
    public int $days = 30;

    /** The day whose searches are listed; empty for the whole period. */
    #[Url]
    public string $day = '';

    public static function getNavigationLabel(): string
    {
        return __('search::ui.analytics.title');
    }

    public function getTitle(): string
    {
        return __('search::ui.analytics.title');
    }

    public function getSubheading(): ?string
    {
        return __('search::ui.analytics.subheading');
    }

    public function mount(): void
    {
        $this->shop ??= app(TenantContext::class)->id() ?? Shop::query()->orderBy('name')->value('id');
    }

    /** False in the merchant panel, where the shop is the one in the address. */
    public function picksShop(): bool
    {
        return true;
    }

    /** @return array<string, string> */
    public function shops(): array
    {
        return Shop::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    public function pick(string $day): void
    {
        $this->day = $this->day === $day ? '' : $day;
    }

    /** @return array<string, mixed>|null */
    public function report(): ?array
    {
        if ($this->shop === null) {
            return null;
        }

        $days = in_array($this->days, [7, 30, 90], true) ? $this->days : 30;
        $since = now()->subDays($days - 1)->startOfDay();

        return app(TenantContext::class)->run($this->shop, function () use ($days, $since): array {
            $rows = SearchTerm::query()->where('day', '>=', $since->toDateString())
                ->selectRaw('day, query, searches, empty, clicks, added')->get();
            $asks = AssistantSearchAsk::query()->where('day', '>=', $since->toDateString())->get(['day', 'outcome']);
            // Orders with products the same visitor added to the cart from the search (RecordOrder).
            $orders = AnalyticsOrder::query()->where('source', AnalyticsOrder::SOURCE_LIVE)->whereNotNull('search_items')
                ->where('ordered_at', '>=', $since)->get(['ordered_at', 'search_total', 'search_items']);

            $daily = [];
            for ($i = 0; $i < $days; $i++) {
                $key = $since->copy()->addDays($i)->toDateString();
                $daily[$key] = ['day' => $key, 'searches' => 0, 'empty' => 0, 'clicks' => 0, 'added' => 0, 'purchases' => 0, 'revenue' => 0.0, 'photos' => 0, 'questions' => 0, 'answered' => 0, 'queries' => 0];
            }

            foreach ($rows as $row) {
                $key = Carbon::parse($row->day)->toDateString();
                if (! isset($daily[$key])) {
                    continue;
                }
                if ($row->query === CountSearch::PHOTO) {
                    $daily[$key]['photos'] += (int) $row->searches;

                    continue;
                }
                $daily[$key]['searches'] += (int) $row->searches;
                $daily[$key]['empty'] += (int) $row->empty;
                $daily[$key]['clicks'] += (int) $row->clicks;
                $daily[$key]['added'] += (int) $row->added;
                $daily[$key]['queries']++;
            }

            $bought = [];
            foreach ($orders as $order) {
                $key = $order->ordered_at->toDateString();
                if (isset($daily[$key])) {
                    $daily[$key]['purchases']++;
                    $daily[$key]['revenue'] += (float) $order->search_total;
                }
                if ($this->day === '' || $this->day === $key) {
                    foreach ((array) $order->search_items as $item) {
                        $bought[(string) $item] = ($bought[(string) $item] ?? 0) + 1;
                    }
                }
            }
            arsort($bought);
            $bought = array_slice($bought, 0, 10, true);
            $titles = CatalogProduct::query()->whereIn('external_id', array_keys($bought))->pluck('title', 'external_id');

            foreach ($asks as $ask) {
                $key = Carbon::parse($ask->day)->toDateString();
                if (isset($daily[$key])) {
                    $daily[$key]['questions']++;
                    $daily[$key]['answered'] += $ask->outcome === 'answered' ? 1 : 0;
                }
            }

            $sum = fn (string $k): int => array_sum(array_column($daily, $k));
            $searches = $sum('searches');
            $day = $this->day !== '' && isset($daily[$this->day]) ? $this->day : null;

            $terms = SearchTerm::query()->where('query', '!=', CountSearch::PHOTO)
                ->when($day !== null, fn ($q) => $q->whereDate('day', $day), fn ($q) => $q->where('day', '>=', $since->toDateString()))
                ->select('query')->selectRaw('SUM(searches) as searches, SUM(empty) as empty, SUM(clicks) as clicks, SUM(added) as added, MAX(last_results) as results')
                ->groupBy('query')->orderByDesc(DB::raw('SUM(searches)'))->orderBy('query')->limit(self::TOP)->get();

            $clicked = SearchClick::query()
                ->when($day !== null, fn ($q) => $q->whereDate('day', $day), fn ($q) => $q->where('day', '>=', $since->toDateString()))
                ->select('item')->selectRaw('MAX(title) as title, SUM(clicks) as clicks, SUM(added) as added')
                ->groupBy('item')->orderByDesc(DB::raw('SUM(clicks)'))->limit(10)->get();

            return [
                'daily' => array_values($daily),
                'max' => max(1, ...array_values(array_map(fn (array $d): int => $d['searches'] + $d['photos'], $daily))),
                'totals' => [
                    'searches' => $searches,
                    'empty' => $searches > 0 ? (int) round(100 * $sum('empty') / $searches) : 0,
                    'clicks' => $searches > 0 ? (int) round(100 * min($searches, $sum('clicks')) / $searches) : 0,
                    'added' => $sum('added'),
                    'purchases' => $sum('purchases'),
                    'revenue' => round(array_sum(array_column($daily, 'revenue')), 2),
                    'photos' => $sum('photos'),
                    'questions' => $sum('questions'),
                    'answered' => $sum('questions') > 0 ? (int) round(100 * $sum('answered') / $sum('questions')) : 0,
                ],
                'day' => $day,
                'terms' => $terms,
                'clicked' => $clicked,
                'bought' => array_map(fn (string $id, int $n): array => ['title' => (string) ($titles[$id] ?? $id), 'orders' => $n], array_map('strval', array_keys($bought)), array_values($bought)),
                'currency' => (string) Shop::query()->whereKey($this->shop)->value('currency'),
            ];
        });
    }
}
