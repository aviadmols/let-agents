<?php

namespace App\Modules\Search\Support;

use App\Modules\Admin\Contracts\TodayFix;
use App\Modules\Admin\Contracts\TodayImprovements;
use App\Modules\Admin\Contracts\TodayWin;
use App\Modules\Analytics\Models\AnalyticsVisit;
use App\Modules\Analytics\Models\AnalyticsVisitStep;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Search\Actions\ChooseWordFamily;
use App\Modules\Search\Filament\Merchant\Pages\WordFamilies;
use App\Modules\Search\Models\SearchClick;
use App\Modules\Search\Models\SearchFamilyChoice;
use App\Modules\Search\Models\SearchSynonym;
use App\Modules\Search\Models\SearchTerm;
use App\Modules\Tenancy\Models\Shop;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The search's fixes on Today. Waiting: the word families written for the store and not decided yet, each one tap
 * from being read as one ("Quick win"), ranked by how often their words were searched. Done: the families and synonyms
 * the owner approved (or a review the owner approved wrote), with what search recorded since that day: searches that
 * found something, adds to the cart from them, and the orders of visits that searched them. Counts only, no model.
 */
final class SearchFixesToday implements TodayImprovements
{
    /** The searches a waiting family is ranked by, over this many days. */
    private const DAYS = 30;

    public function waiting(Shop $shop): array
    {
        $fixes = [];
        foreach (StoreFamilies::waiting() as $words) {
            $plain = array_values(array_unique(array_map(fn (string $w): string => HebrewSearch::normalize($w), $words)));
            $searches = (int) SearchTerm::query()->whereIn('query', $plain)->where('day', '>=', now()->subDays(self::DAYS)->toDateString())->sum('searches');
            $shown = array_map(fn (string $w): string => HebrewSearch::withFinals($w), array_slice($words, 0, 3));
            $fixes[] = new TodayFix(
                key: 'family:'.StoreFamilies::key($words),
                chip: $searches >= 10 ? TodayFix::QUICK : TodayFix::NICE,
                count: $searches,
                unit: 'searches',
                title: __('search::today.fix.family.title', ['words' => implode(' · ', $shown)]),
                text: trans_choice('search::today.fix.family.text', count($words), ['n' => count($words)]),
                action: __('search::today.fix.family.action'),
                oneTap: true,
                url: WordFamilies::getUrl(panel: 'merchant', tenant: $shop),
                pictures: $this->pictures($plain, now()->subDays(self::DAYS)),
                impact: $searches,
                kind: 'nothing',
                // the words, each as searched, read as one from now on
                suggestion: ['type' => 'words', 'words' => array_map(fn (string $w): string => HebrewSearch::withFinals($w), $words)],
                typed: $this->typed($plain),
                secondary: ['label' => __('search::today.fix.family.edit'), 'url' => WordFamilies::getUrl(panel: 'merchant', tenant: $shop)],
            );
        }

        return $fixes;
    }

    /** "Not relevant": not one family, never offered again as one (the word families screen's "wrong"). */
    public function dismiss(Shop $shop, string $key, ?int $userId): bool
    {
        if (! str_starts_with($key, 'family:')) {
            return false;
        }
        foreach (StoreFamilies::waiting() as $words) {
            if ('family:'.StoreFamilies::key($words) === $key) {
                app(ChooseWordFamily::class)->handle((string) $shop->id, SearchFamilyChoice::REJECTED, $words);

                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $plain
     * @return list<array{text: string, n: int}> how often each word was searched in the last month
     */
    private function typed(array $plain): array
    {
        return SearchTerm::query()->whereIn('query', $plain)->where('day', '>=', now()->subDays(self::DAYS)->toDateString())
            ->select('query')->selectRaw('SUM(searches) as n')->groupBy('query')->orderByDesc(DB::raw('SUM(searches)'))->get()
            ->map(fn ($r): array => ['text' => HebrewSearch::withFinals((string) $r->query), 'n' => (int) $r->n])->all();
    }

    public function approve(Shop $shop, string $key, ?int $userId, ?string $text = null): ?string
    {
        if (! str_starts_with($key, 'family:')) {
            return null;
        }
        foreach (StoreFamilies::waiting() as $words) {
            if ('family:'.StoreFamilies::key($words) === $key) {
                app(ChooseWordFamily::class)->handle((string) $shop->id, SearchFamilyChoice::APPROVED, $words);

                return __('search::today.fix.family.done');
            }
        }

        return null;
    }

    public function wins(Shop $shop, int $limit): array
    {
        $approved = [];
        foreach (SearchFamilyChoice::query()->where('status', SearchFamilyChoice::APPROVED)->where('auto', false)->latest('created_at')->limit($limit)->get(['id', 'words', 'created_at']) as $choice) {
            $words = array_values(array_map('strval', (array) $choice->words));
            if (count($words) >= 2) {
                $approved[] = ['key' => 'family:'.$choice->id, 'said' => $words[1], 'means' => $words[0], 'words' => $words, 'at' => $choice->created_at];
            }
        }
        foreach (SearchSynonym::query()->whereIn('origin', ['team', 'review'])->latest('created_at')->limit($limit)->get(['id', 'term', 'means', 'created_at']) as $synonym) {
            $approved[] = ['key' => 'synonym:'.$synonym->id, 'said' => (string) $synonym->term, 'means' => (string) $synonym->means, 'words' => [(string) $synonym->term], 'at' => $synonym->created_at];
        }
        usort($approved, fn (array $a, array $b): int => [$b['at'], $a['key']] <=> [$a['at'], $b['key']]);

        $wins = [];
        foreach (array_slice($approved, 0, $limit) as $fix) {
            $plain = array_values(array_unique(array_map(fn (string $w): string => HebrewSearch::normalize($w), $fix['words'])));
            $since = Carbon::instance($fix['at']);
            $terms = SearchTerm::query()->whereIn('query', $plain)->where('day', '>=', $since->toDateString())
                ->selectRaw('COALESCE(SUM(searches), 0) as searches, COALESCE(SUM(empty), 0) as empty, COALESCE(SUM(added), 0) as added')->first();
            $best = $this->best($plain, $since);
            $wins[] = new TodayWin(
                key: $fix['key'],
                searched: HebrewSearch::withFinals($fix['said']),
                finds: $best['title'] ?? HebrewSearch::withFinals($fix['means']),
                approvedAt: $since,
                found: max(0, (int) ($terms->searches ?? 0) - (int) ($terms->empty ?? 0)),
                adds: (int) ($terms->added ?? 0),
                sales: $this->sales($plain, $since),
                picture: $best['picture'] ?? null,
            );
        }

        return $wins;
    }

    /**
     * The orders of visits that searched one of the words since, each visit once.
     *
     * @param  list<string>  $plain  normalized words
     */
    private function sales(array $plain, CarbonInterface $since): ?float
    {
        $visits = AnalyticsVisitStep::query()->where('kind', 'search')->where('at', '>=', $since)->whereNotNull('label')->get(['visit_id', 'label'])
            ->filter(fn (AnalyticsVisitStep $step): bool => in_array(HebrewSearch::normalize((string) $step->label), $plain, true))
            ->pluck('visit_id')->unique()->values()->all();
        if ($visits === []) {
            return null;
        }
        $total = (float) AnalyticsVisit::query()->whereIn('id', $visits)->where('preview', false)->sum('order_total');

        return $total > 0 ? round($total, 2) : null;
    }

    /**
     * @param  list<string>  $plain
     * @return array{title: string, picture: string|null}|null the product opened most after the words since
     */
    private function best(array $plain, CarbonInterface $since): ?array
    {
        $row = SearchClick::query()->whereIn('query', $plain)->where('day', '>=', $since->toDateString())
            ->select('item')->selectRaw('MAX(title) as title, SUM(clicks) as clicks')->groupBy('item')->orderByDesc('clicks')->orderBy('item')->first();
        if ($row === null) {
            return null;
        }
        $product = CatalogProduct::query()->where('external_id', self::bare((string) $row->item))->first(['title', 'image_url']);

        return ['title' => (string) ($product?->title ?: $row->title), 'picture' => $product?->image_url];
    }

    /**
     * @param  list<string>  $plain
     * @return list<string> the photos of the two products opened most after the words
     */
    private function pictures(array $plain, CarbonInterface $since): array
    {
        $items = SearchClick::query()->whereIn('query', $plain)->where('day', '>=', $since->toDateString())
            ->select('item')->selectRaw('SUM(clicks) as clicks')->groupBy('item')->orderByDesc('clicks')->orderBy('item')->limit(2)->pluck('item')
            ->map(fn ($item): string => self::bare((string) $item))->all();

        return CatalogProduct::query()->whereIn('external_id', $items)->whereNotNull('image_url')->pluck('image_url')->map(fn ($u): string => (string) $u)->values()->all();
    }

    private static function bare(string $item): string
    {
        return str_starts_with($item, 'p:') ? substr($item, 2) : $item;
    }
}
