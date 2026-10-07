<?php

namespace App\Modules\Search\Filament\Operator\Pages;

use App\Core\Tenancy\TenantContext;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Search\Actions\BuildSearchIndex;
use App\Modules\Search\Actions\ResolveEmptySearches;
use App\Modules\Search\Actions\WritePageTags;
use App\Modules\Search\Models\SearchClick;
use App\Modules\Search\Models\SearchIndex;
use App\Modules\Search\Models\SearchPageTags;
use App\Modules\Search\Models\SearchResolution;
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
                'pageTags' => SearchPageTags::query()->orderByDesc('updated_at')->orderBy('id')->limit(40)->get()->filter(fn (SearchPageTags $t): bool => $t->shown() !== [])->values(),
                'resolutions' => SearchResolution::query()->orderByRaw("CASE status WHEN 'resolved' THEN 0 WHEN 'refused' THEN 1 WHEN 'none' THEN 2 ELSE 3 END")->orderByDesc('searches')->limit(50)->get(),
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

    /** The team takes a resolution back: that search shows its regular results again. */
    public function undoResolution(string $id): void
    {
        $this->setResolution($id, SearchResolution::REJECTED, 'undone');
    }

    public function restoreResolution(string $id): void
    {
        $this->setResolution($id, SearchResolution::RESOLVED, 'restored');
    }

    private function setResolution(string $id, string $status, string $message): void
    {
        if ($this->shop === null) {
            return;
        }

        app(TenantContext::class)->run($this->shop, function () use ($id, $status): void {
            $resolution = SearchResolution::query()->find($id);

            if ($resolution === null) {
                return;
            }

            $resolution->update(['status' => $status, 'decided_by' => auth()->id(), 'decided_at' => now()]);

            // The synonym the night added goes and comes back with its resolution.
            if ($resolution->synonym_means !== null) {
                $status === SearchResolution::RESOLVED
                    ? SearchSynonym::query()->firstOrCreate(['term' => $resolution->query, 'means' => $resolution->synonym_means], ['shop_id' => $this->shop, 'origin' => 'resolution'])
                    : SearchSynonym::query()->where('term', $resolution->query)->where('means', $resolution->synonym_means)->where('origin', 'resolution')->delete();
            }
        });
        Notification::make()->success()->title(__('search::ui.resolved.'.$message))->send();
    }

    /** The team takes a tag off one page; the next night does not write it back. */
    public function hideTag(string $id, string $label): void
    {
        if ($this->shop === null) {
            return;
        }

        app(TenantContext::class)->run($this->shop, function () use ($id, $label): void {
            $row = SearchPageTags::query()->find($id);
            $row?->update(['hidden_labels' => array_values(array_unique([...(array) $row->hidden_labels, $label]))]);
        });
        Notification::make()->success()->title(__('search::ui.tags.removed'))->send();
    }

    /** Writes the page tags now, without waiting for the night. */
    public function writeTagsNow(): void
    {
        if ($this->shop === null) {
            return;
        }

        $run = app(WritePageTags::class)->handle($this->shop, RunTrigger::Manual);

        $run->status->value === 'succeeded'
            ? Notification::make()->success()->title(__('search::ui.tags.written'))->body($run->summary())->send()
            : Notification::make()->danger()->title(__('search::ui.index.failed'))->send();
    }

    /** Resolves the empty searches now, without waiting for the night. */
    public function resolveNow(): void
    {
        if ($this->shop === null) {
            return;
        }

        $run = app(ResolveEmptySearches::class)->handle($this->shop, RunTrigger::Manual);

        $run->status->value === 'succeeded'
            ? Notification::make()->success()->title(__('search::ui.resolved.resolved_now'))->body($run->summary())->send()
            : Notification::make()->danger()->title(__('search::ui.index.failed'))->send();
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
