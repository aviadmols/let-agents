<?php

namespace App\Modules\Search\Support;

use App\Modules\Admin\Contracts\ReportSeries;
use App\Modules\Admin\Contracts\TodaySearches;
use App\Modules\Assistant\Models\AssistantSearchAsk;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Search\Filament\Merchant\Pages\SearchLogPage;
use App\Modules\Search\Models\SearchClick;
use App\Modules\Search\Models\SearchLog;
use App\Modules\Search\Models\SearchPhotoAsk;
use App\Modules\Search\Models\SearchTerm;
use App\Modules\Tenancy\Models\Shop;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The search's part of Today: searches and questions with how many found something (search_terms, the assistant's
 * asks), the words searched most with the product most clicked for each (search_clicks), what went to the cart from
 * search with the words that found it, and the live feed (search_logs, photo searches, questions) with everything
 * the log keeps about each, for the owner to open. Asked inside the shop. Counts only, no model.
 */
final class SearchToday implements TodaySearches
{
    private const PHOTO = '[photo]';

    public function found(Shop $shop, CarbonInterface $since, CarbonInterface $until): array
    {
        $terms = $this->terms($since, $until)->selectRaw('COALESCE(SUM(searches), 0) as searches, COALESCE(SUM(empty), 0) as empty')->first();
        $asks = AssistantSearchAsk::query()->where('created_at', '>=', $since)->where('created_at', '<', $until);
        $searches = (int) ($terms->searches ?? 0);
        $questions = (clone $asks)->count();

        return [
            'searches' => $searches,
            'questions' => $questions,
            'found' => max(0, $searches - (int) ($terms->empty ?? 0)) + (clone $asks)->where('outcome', 'answered')->count(),
        ];
    }

    public function top(Shop $shop, CarbonInterface $since, CarbonInterface $until, CarbonInterface $before, int $limit): array
    {
        $rows = $this->terms($since, $until)->where('query', '!=', self::PHOTO)
            ->select('query')->selectRaw('SUM(searches) as searches')->groupBy('query')
            ->orderByDesc(DB::raw('SUM(searches)'))->orderBy('query')->limit($limit)->get();
        $queries = $rows->pluck('query')->map(fn ($q): string => (string) $q)->all();
        if ($queries === []) {
            return [];
        }

        $earlier = $this->terms($before, $since)->whereIn('query', $queries)->select('query')->selectRaw('SUM(searches) as searches')->groupBy('query')->pluck('searches', 'query');
        $clicked = $this->mostClicked($queries, $since, $until);

        return $rows->map(fn ($row): array => [
            // the words as kept are normalized (final letters plain): shown as written
            'query' => HebrewSearch::withFinals((string) $row->query),
            'searches' => (int) $row->searches,
            'before' => (int) ($earlier[(string) $row->query] ?? 0),
            'found' => $clicked[(string) $row->query]['title'] ?? null,
            'picture' => $clicked[(string) $row->query]['picture'] ?? null,
        ])->all();
    }

    public function topOn(Shop $shop, CarbonInterface $day): ?string
    {
        $query = SearchTerm::query()->whereDate('day', Carbon::instance($day)->timezone(ReportSeries::TZ)->toDateString())->where('query', '!=', self::PHOTO)
            ->orderByDesc('searches')->orderBy('query')->value('query');

        return $query === null ? null : HebrewSearch::withFinals((string) $query);
    }

    public function sold(Shop $shop, CarbonInterface $since, CarbonInterface $until, int $limit): array
    {
        $clicks = SearchClick::query()->where('day', '>=', $since->toDateString())->where('day', '<', $this->end($until))->where('added', '>', 0)
            ->get(['query', 'item', 'title', 'added']);
        $items = [];
        foreach ($clicks as $click) {
            $item = &$items[(string) $click->item];
            $item ??= ['title' => (string) $click->title, 'adds' => 0, 'by' => []];
            $item['adds'] += (int) $click->added;
            $item['by'][(string) $click->query] = ($item['by'][(string) $click->query] ?? 0) + (int) $click->added;
            unset($item);
        }

        uasort($items, fn (array $a, array $b): int => [$b['adds'], $a['title']] <=> [$a['adds'], $b['title']]);
        $items = array_slice($items, 0, $limit, true);
        $products = $this->products(array_keys($items));

        $sold = [];
        foreach ($items as $id => $item) {
            arsort($item['by']);
            $phrase = (string) array_key_first($item['by']);
            $product = $products[self::bare($id)] ?? null;
            $title = trim((string) ($product?->title ?: $item['title']));
            if ($title === '') {
                continue;
            }
            $sold[] = [
                'title' => $title,
                'picture' => $product?->image_url,
                'adds' => $item['adds'],
                'phrase' => $phrase === self::PHOTO ? null : HebrewSearch::withFinals($phrase),
                'photo' => $phrase === self::PHOTO,
            ];
        }

        return $sold;
    }

    public function feed(Shop $shop, string $search, int $limit): array
    {
        $words = mb_strtolower(trim($search));
        $logs = SearchLog::query()->latest('id')->limit($limit)
            ->when($words !== '', fn (Builder $q) => $q->where('query', 'like', '%'.$words.'%'))->get();
        $photos = $words === '' ? SearchPhotoAsk::query()->latest()->limit($limit)
            ->get(['id', 'object', 'results', 'total', 'created_at', 'ip', 'device', 'browser', 'os', 'session_id', 'page', 'referrer']) : collect();
        $asks = AssistantSearchAsk::query()->latest()->limit($limit)
            ->when($words !== '', fn (Builder $q) => $q->where('question', 'like', '%'.$words.'%'))
            ->get(['id', 'question', 'outcome', 'from', 'picks', 'created_at']);

        $ids = $logs->pluck('clicked')->filter()->map(fn (string $id): string => self::bare($id))
            ->merge($asks->flatMap(fn (AssistantSearchAsk $a): array => array_map(fn (array $p): string => (string) ($p['external_id'] ?? ''), (array) $a->picks)))
            ->filter()->unique()->values()->all();
        $products = CatalogProduct::query()->whereIn('external_id', $ids)->get(['external_id', 'title', 'image_url'])->keyBy('external_id');

        $rows = [];
        foreach ($logs as $log) {
            $product = $log->clicked === null ? null : ($products[self::bare($log->clicked)] ?? null);
            $rows[] = [
                'id' => 's'.$log->id,
                'at' => $log->created_at,
                'kind' => 'search',
                'query' => (string) $log->query,
                'got' => $product !== null ? (string) $product->title : ((int) $log->results === 0 ? __('search::today.got.nothing') : trans_choice('search::today.got.results', (int) $log->results, ['n' => number_format((int) $log->results)])),
                'picture' => $product?->image_url,
                'url' => $log->session_id ? SearchLogPage::getUrl(['session' => $log->session_id]) : null,
                'details' => $this->details($log->created_at, [
                    'results' => number_format((int) $log->results),
                    'clicked' => $product?->title ?? ($log->clicked === null ? null : (string) $log->clicked),
                ] + $this->visit($log)),
            ];
        }
        foreach ($photos as $photo) {
            $first = (array) (($photo->results ?? [])[0] ?? []);
            $rows[] = [
                'id' => 'p'.$photo->id,
                'at' => $photo->created_at,
                'kind' => 'photo',
                'query' => $photo->object ? (string) $photo->object : __('search::today.photo'),
                'got' => isset($first['title']) ? (string) $first['title'] : __('search::today.got.nothing'),
                'picture' => isset($first['image']) ? (string) $first['image'] : null,
                'url' => $photo->session_id ? SearchLogPage::getUrl(['session' => $photo->session_id]) : null,
                'details' => $this->details($photo->created_at, ['results' => number_format((int) $photo->total)] + $this->visit($photo)),
            ];
        }
        foreach ($asks as $ask) {
            $pick = (array) (((array) $ask->picks)[0] ?? []);
            $product = $products[(string) ($pick['external_id'] ?? '')] ?? null;
            $answered = $ask->outcome === 'answered';
            $rows[] = [
                'id' => 'q'.$ask->id,
                'at' => $ask->created_at,
                'kind' => 'ask',
                'query' => (string) $ask->question,
                'got' => $answered ? ($product !== null ? (string) $product->title : __('search::today.got.answered')) : __('search::today.got.unanswered'),
                'picture' => $product?->image_url,
                'url' => null,
                'details' => $this->details($ask->created_at, ['answer' => __('search::today.outcome.'.($answered ? 'answered' : 'open'))]),
            ];
        }

        usort($rows, fn (array $a, array $b): int => $b['at'] <=> $a['at']);

        return array_slice($rows, 0, $limit);
    }

    /**
     * Everything recorded about one search, in the order the owner reads it.
     *
     * @param  array<string, string|null>  $values
     * @return list<array{0: string, 1: string}>
     */
    private function details(?CarbonInterface $at, array $values): array
    {
        $rows = [[__('search::today.details.when'), $at === null ? '' : Carbon::instance($at)->timezone(ReportSeries::TZ)->format('j.n.Y H:i:s')]];
        foreach ($values as $key => $value) {
            if ($value !== null && $value !== '') {
                $rows[] = [__('search::today.details.'.$key), (string) $value];
            }
        }

        return $rows;
    }

    /** @return array<string, string|null> the visit a search came in (Visit::COLUMNS), kept until search.visit_keep_days */
    private function visit(SearchLog|SearchPhotoAsk $row): array
    {
        return [
            'ip' => $row->ip, 'device' => $row->device, 'browser' => $row->browser, 'os' => $row->os,
            'page' => $row->page, 'referrer' => $row->referrer, 'session' => $row->session_id,
        ];
    }

    /**
     * The product clicked most after each word, in the period.
     *
     * @param  list<string>  $queries
     * @return array<string, array{title: string, picture: string|null}>
     */
    private function mostClicked(array $queries, CarbonInterface $since, CarbonInterface $until): array
    {
        $best = [];
        foreach (SearchClick::query()->whereIn('query', $queries)->where('day', '>=', $since->toDateString())->where('day', '<', $this->end($until))
            ->select('query', 'item')->selectRaw('MAX(title) as title, SUM(clicks) as clicks')->groupBy('query', 'item')->get() as $row) {
            $q = (string) $row->query;
            if (! isset($best[$q]) || (int) $row->clicks > $best[$q]['clicks'] || ((int) $row->clicks === $best[$q]['clicks'] && (string) $row->item < $best[$q]['item'])) {
                $best[$q] = ['item' => (string) $row->item, 'title' => (string) $row->title, 'clicks' => (int) $row->clicks];
            }
        }
        $products = $this->products(array_column($best, 'item'));

        return array_map(fn (array $b): array => [
            'title' => (string) ($products[self::bare($b['item'])]->title ?? $b['title']),
            'picture' => $products[self::bare($b['item'])]->image_url ?? null,
        ], $best);
    }

    /**
     * @param  list<string>  $items  'p:{external_id}'
     * @return Collection<string, CatalogProduct>
     */
    private function products(array $items)
    {
        return CatalogProduct::query()->whereIn('external_id', array_map(self::bare(...), $items))->get(['external_id', 'title', 'image_url'])->keyBy('external_id');
    }

    private static function bare(string $item): string
    {
        return str_starts_with($item, 'p:') ? substr($item, 2) : $item;
    }

    private function terms(CarbonInterface $since, CarbonInterface $until): Builder
    {
        return SearchTerm::query()->where('day', '>=', $since->toDateString())->where('day', '<', $this->end($until));
    }

    /** The day after the period's last, for a day column. */
    private function end(CarbonInterface $until): string
    {
        return $until->copy()->subSecond()->addDay()->toDateString();
    }
}
