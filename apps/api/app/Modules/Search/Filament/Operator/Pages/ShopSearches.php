<?php

namespace App\Modules\Search\Filament\Operator\Pages;

use App\Core\Tenancy\TenantContext;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Search\Actions\BuildSearchIndex;
use App\Modules\Search\Models\SearchClick;
use App\Modules\Search\Models\SearchIndex;
use App\Modules\Search\Models\SearchSynonym;
use App\Modules\Search\Models\SearchTerm;
use App\Modules\Search\Support\HebrewSearch;
use App\Modules\Tenancy\Models\Shop;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;

/**
 * What shoppers searched in one store: the most searched, the searches that found nothing (the
 * list the store learns most from), and what was clicked. The team adds synonyms here, so a word
 * shoppers use finds the products the store names differently.
 */
class ShopSearches extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlass;

    protected static ?int $navigationSort = 16;

    protected static ?string $slug = 'search/terms';

    protected string $view = 'search::operator.searches';

    private const TOP = 25;

    #[Url]
    public ?string $shop = null;

    #[Url]
    public int $days = 30;

    public string $synonymTerm = '';

    public string $synonymMeans = '';

    public static function getNavigationLabel(): string
    {
        return __('search::ui.title');
    }

    public function getTitle(): string
    {
        return __('search::ui.title');
    }

    public function getSubheading(): ?string
    {
        return __('search::ui.subheading');
    }

    public function mount(): void
    {
        $this->shop ??= app(TenantContext::class)->id() ?? Shop::query()->orderBy('name')->value('id');
    }

    /** False in the merchant panel, where the shop is the one in the address and cannot change. */
    public function picksShop(): bool
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
        if ($this->shop === null) {
            return null;
        }

        return app(TenantContext::class)->run($this->shop, function (): array {
            $since = now()->subDays($this->window() - 1)->toDateString();
            $terms = SearchTerm::query()->where('day', '>=', $since);

            $totals = (clone $terms)->selectRaw('COALESCE(SUM(searches),0) as searches, COALESCE(SUM(empty),0) as empty, COALESCE(SUM(clicks),0) as clicks, COUNT(DISTINCT query) as distinct_queries')->first();
            $searches = (int) $totals->searches;

            $grouped = fn () => (clone $terms)->select('query')
                ->selectRaw('SUM(searches) as searches, SUM(empty) as empty, SUM(clicks) as clicks, MAX(last_results) as results')
                ->groupBy('query');

            return [
                'totals' => [
                    'searches' => $searches,
                    'distinct' => (int) $totals->distinct_queries,
                    'empty_share' => $searches > 0 ? (int) round(100 * (int) $totals->empty / $searches) : 0,
                    'click_share' => $searches > 0 ? (int) round(100 * min($searches, (int) $totals->clicks) / $searches) : 0,
                ],
                'top' => $grouped()->orderByDesc(DB::raw('SUM(searches)'))->orderBy('query')->limit(self::TOP)->get(),
                'empty' => $grouped()->havingRaw('SUM(empty) > 0')->orderByDesc(DB::raw('SUM(empty)'))->orderBy('query')->limit(self::TOP)->get(),
                'clicked' => SearchClick::query()->where('day', '>=', $since)
                    ->select('item')->selectRaw('MAX(title) as title, SUM(clicks) as clicks, COUNT(DISTINCT query) as queries')
                    ->groupBy('item')->orderByDesc(DB::raw('SUM(clicks)'))->limit(self::TOP)->get(),
                'synonyms' => SearchSynonym::query()->orderBy('term')->get(),
                'index' => SearchIndex::query()->first(['hash', 'counts', 'built_at']),
            ];
        });
    }

    /** 7, 30 or 90 days; anything else put into the address reads as 30. */
    public function window(): int
    {
        return in_array($this->days, [7, 30, 90], true) ? $this->days : 30;
    }

    public function addSynonym(): void
    {
        $term = mb_substr(trim($this->synonymTerm), 0, 80);
        $means = mb_substr(trim($this->synonymMeans), 0, 120);

        if ($this->shop === null || HebrewSearch::normalize($term) === '' || HebrewSearch::normalize($means) === '' || HebrewSearch::normalize($term) === HebrewSearch::normalize($means)) {
            Notification::make()->warning()->title(__('search::ui.synonyms.invalid'))->send();

            return;
        }

        app(TenantContext::class)->run($this->shop, fn () => SearchSynonym::query()->firstOrCreate(
            ['term' => $term, 'means' => $means],
            ['shop_id' => $this->shop, 'origin' => 'team'],
        ));

        $this->synonymTerm = '';
        $this->synonymMeans = '';
        Notification::make()->success()->title(__('search::ui.synonyms.added'))->body(__('search::ui.synonyms.applies_after_build'))->send();
    }

    public function removeSynonym(string $id): void
    {
        if ($this->shop === null) {
            return;
        }

        app(TenantContext::class)->run($this->shop, fn () => SearchSynonym::query()->whereKey($id)->delete());
        Notification::make()->success()->title(__('search::ui.synonyms.removed'))->send();
    }

    /** Builds the index now, so new products and synonyms are searchable without waiting for the night. */
    public function buildNow(): void
    {
        if ($this->shop === null) {
            return;
        }

        $run = app(BuildSearchIndex::class)->handle($this->shop, RunTrigger::Manual);

        $run->status->value === 'succeeded'
            ? Notification::make()->success()->title(__('search::ui.index.built'))->body($run->summary())->send()
            : Notification::make()->danger()->title(__('search::ui.index.failed'))->send();
    }
}
