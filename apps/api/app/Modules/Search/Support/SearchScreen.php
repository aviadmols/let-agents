<?php

namespace App\Modules\Search\Support;

use App\Core\Localization\Money;
use App\Modules\Admin\Contracts\ReportSeries;
use App\Modules\Admin\Contracts\SearchesScreen;
use App\Modules\Analytics\Models\AnalyticsVisit;
use App\Modules\Analytics\Models\AnalyticsVisitStep;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Assistant\Models\AssistantSearchAsk;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Search\Actions\SearchCatalog;
use App\Modules\Search\Models\SearchClick;
use App\Modules\Search\Models\SearchPhotoAsk;
use App\Modules\Search\Models\SearchTerm;
use App\Modules\Tenancy\Models\Shop;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The Searches screen from what search records (the designer's redesign, 11.2026): searches and questions with how many
 * found something (search_terms, the assistant's asks), photo searches and what went to the cart from them
 * (search_clicks under "[photo]"), the days, the words with what was opened after them, what went to the cart and the
 * orders of visits that searched them, what is rising, the questions asked most and where their answers came from, the
 * latest photo searches, the phrases that come back, and the store's own search for the owner to try. Asked inside the
 * shop. Counts and code only: no model, nothing counted.
 */
final class SearchScreen implements SearchesScreen
{
    private const PHOTO = '[photo]';

    /** A word with fewer searches than this is not "rising" on its growth alone. */
    private const RISING_MIN = 3;

    /** Words a phrase never starts or ends with: they say how, not what. */
    private const STOP = ['של', 'עם', 'על', 'את', 'או', 'גם', 'יש', 'מה', 'איך', 'כמה', 'אני', 'לי', 'זה', 'הכי', 'עד', 'כל', 'אם', 'לא', 'כן',
        'אצלכם', 'לכם', 'שלכם', 'אפשר', 'רוצה', 'מחפש', 'מחפשת',
        'the', 'a', 'an', 'of', 'and', 'or', 'with', 'to', 'in', 'on', 'is', 'are', 'do', 'you', 'i', 'me', 'my', 'it', 'what', 'how', 'any', 'have',
        'for', 'from', 'by', 'at', 'up', 'something', 'some', 'can', 'your', 'want', 'need'];

    public function __construct(private readonly SearchToday $today) {}

    public function kpis(Shop $shop, CarbonInterface $since, CarbonInterface $until, CarbonInterface $before): array
    {
        $count = fn (CarbonInterface $from, CarbonInterface $to, bool $photo): int => (int) $this->terms($from, $to)->where('query', $photo ? '=' : '!=', self::PHOTO)->sum('searches');
        $asks = AssistantSearchAsk::query()->where('created_at', '>=', $since)->where('created_at', '<', $until);
        $found = $this->today->found($shop, $since, $until);

        return [
            'searches' => $count($since, $until, false),
            'searches_before' => $count($before, $since, false),
            'questions' => (clone $asks)->count(),
            'answered' => (clone $asks)->where('outcome', 'answered')->count(),
            'photos' => $count($since, $until, true),
            'photo_adds' => (int) SearchClick::query()->where('query', self::PHOTO)->where('day', '>=', $since->toDateString())->where('day', '<', $this->end($until))->sum('added'),
            'found' => $found['found'],
            'asked' => $found['searches'] + $found['questions'],
        ];
    }

    public function daily(Shop $shop, CarbonInterface $since, CarbonInterface $until): array
    {
        $format = ReportSeries::scale($since, $until)['format'];
        $searches = [];
        $adds = [];
        foreach ($this->terms($since, $until)->select('day')->selectRaw('SUM(searches) as searches, SUM(added) as added')->groupBy('day')->get() as $row) {
            // a day kept as a date: counted under its own key, in the store's clock (noon, so no zone moves it a day)
            $key = ReportSeries::key(substr((string) $row->day, 0, 10).' 12:00:00', $format);
            $searches[$key] = ($searches[$key] ?? 0) + (int) $row->searches;
            $adds[$key] = ($adds[$key] ?? 0) + (int) $row->added;
        }

        return ['searches' => $searches, 'adds' => $adds];
    }

    public function rows(Shop $shop, CarbonInterface $since, CarbonInterface $until, CarbonInterface $before, string $sort, int $limit): array
    {
        $grouped = $this->terms($since, $until)->where('query', '!=', self::PHOTO)->select('query')
            ->selectRaw('SUM(searches) as searches, SUM(empty) as empty, SUM(clicks) as clicks, SUM(added) as added, MAX(last_results) as results')->groupBy('query');
        // enough of them to sort by sales or by what needs a fix among the words searched most
        $rows = (clone $grouped)->orderByDesc(DB::raw('SUM(searches)'))->orderBy('query')->limit($sort === 'searched' ? $limit : max($limit * 6, 60))->get();
        if ($rows->isEmpty()) {
            return [];
        }
        $queries = $rows->pluck('query')->map(fn ($q): string => (string) $q)->all();
        $earlier = $this->terms($before, $since)->whereIn('query', $queries)->select('query')->selectRaw('SUM(searches) as searches')->groupBy('query')->pluck('searches', 'query');
        $clicked = $this->clicked($queries, $since, $until);
        $sales = $this->sales($queries, $since, $until);

        $out = $rows->map(function ($row) use ($earlier, $clicked, $sales): array {
            $q = (string) $row->query;
            $searches = (int) $row->searches;
            $clicks = (int) $row->clicks;
            $empty = (int) $row->empty >= max(1, (int) ceil($searches / 2));
            $pct = $searches > 0 ? (int) min(100, round(100 * $clicks / $searches)) : null;

            return [
                'query' => HebrewSearch::withFinals($q),
                'searches' => $searches,
                'before' => (int) ($earlier[$q] ?? 0),
                'clicked' => $empty ? 0 : $pct,
                'adds' => (int) $row->added,
                'sales' => (float) ($sales[$q] ?? 0),
                'found' => $empty ? null : ($clicked[$q]['title'] ?? null),
                'picture' => $clicked[$q]['picture'] ?? null,
                'empty' => $empty,
                // found nothing, or found what nobody opens: a word worth a fix
                'fix' => $empty || ($searches >= 5 && $pct !== null && $pct < 10),
            ];
        })->all();

        if ($sort === 'sales') {
            usort($out, fn (array $a, array $b): int => [$b['sales'], $b['adds'], $b['searches']] <=> [$a['sales'], $a['adds'], $a['searches']]);
        } elseif ($sort === 'fix') {
            $out = array_values(array_filter($out, fn (array $r): bool => $r['fix']));
        }

        return array_slice($out, 0, $limit);
    }

    public function rising(Shop $shop, CarbonInterface $since, CarbonInterface $until, CarbonInterface $before, int $limit): array
    {
        $rows = $this->rows($shop, $since, $until, $before, 'searched', 40);
        $rows = array_values(array_filter($rows, fn (array $r): bool => $r['searches'] >= self::RISING_MIN && $r['searches'] > $r['before']));
        $growth = fn (array $r): float => $r['before'] === 0 ? INF : ($r['searches'] - $r['before']) / $r['before'];
        usort($rows, fn (array $a, array $b): int => [$growth($b), $b['searches']] <=> [$growth($a), $a['searches']]);

        return array_map(fn (array $r): array => ['query' => $r['query'], 'searches' => $r['searches'], 'before' => $r['before'], 'picture' => $r['picture']], array_slice($rows, 0, $limit));
    }

    public function questions(Shop $shop, CarbonInterface $since, CarbonInterface $until, int $limit): array
    {
        return AssistantAnswer::query()->where('status', AssistantAnswer::SHOWN)->whereIn('outcome', [AssistantAnswer::ANSWERED, AssistantAnswer::NO_INFO])
            ->where('last_asked_at', '>=', $since)->where('last_asked_at', '<', $until)
            ->orderByDesc('asked_count')->orderByDesc('last_asked_at')->limit($limit)->get(['question', 'asked_count', 'outcome', 'source', 'sources', 'product_id'])
            ->map(function (AssistantAnswer $a): array {
                $answered = $a->outcome === AssistantAnswer::ANSWERED;
                $page = collect((array) $a->sources)->first(fn ($s): bool => is_array($s) && trim((string) ($s['title'] ?? '')) !== '');
                $from = match (true) {
                    ! $answered => 'none',
                    $a->source === 'team' => 'team',
                    $page !== null => 'page',
                    $a->product_id !== null => 'product',
                    default => 'general',
                };

                return ['question' => trim((string) $a->question), 'times' => max(1, (int) $a->asked_count), 'answered' => $answered, 'from' => $from, 'where' => $page === null ? null : trim((string) $page['title'])];
            })->all();
    }

    public function photos(Shop $shop, CarbonInterface $since, CarbonInterface $until, int $limit): array
    {
        return SearchPhotoAsk::query()->where('created_at', '>=', $since)->where('created_at', '<', $until)->where('total', '>', 0)
            ->latest()->limit($limit)->get(['thumb', 'object', 'results'])
            ->map(function (SearchPhotoAsk $ask): ?array {
                $first = (array) (((array) $ask->results)[0] ?? []);
                if (! isset($first['title'])) {
                    return null;
                }
                $thumb = (string) ($ask->thumb ?? '');

                return [
                    'photo' => $thumb !== '' && preg_match('/^[A-Za-z0-9+\/=]+$/', $thumb) === 1 ? 'data:image/jpeg;base64,'.$thumb : null,
                    'title' => (string) $first['title'],
                    'picture' => isset($first['image']) ? (string) $first['image'] : null,
                    'seen' => $ask->object ? (string) $ask->object : null,
                ];
            })->filter()->values()->all();
    }

    public function words(Shop $shop, CarbonInterface $since, CarbonInterface $until, int $limit): array
    {
        $said = [];
        foreach ($this->terms($since, $until)->where('query', '!=', self::PHOTO)->select('query')->selectRaw('SUM(searches) as n')->groupBy('query')->get() as $row) {
            $said[] = [HebrewSearch::withFinals((string) $row->query), (int) $row->n];
        }
        foreach (AssistantSearchAsk::query()->where('created_at', '>=', $since)->where('created_at', '<', $until)->limit(2000)->pluck('question') as $question) {
            $said[] = [(string) $question, 1];
        }

        // every word and every two words side by side, weighed by how often they were searched
        $counts = [];
        foreach ($said as [$text, $n]) {
            $words = array_values(array_filter(preg_split('/[^\p{L}\p{N}$₪]+/u', mb_strtolower($text)) ?: [], fn (string $w): bool => $w !== ''));
            $seen = [];
            foreach ($words as $i => $w) {
                if (mb_strlen($w) >= 2 && ! in_array($w, self::STOP, true) && ! preg_match('/^\d+$/', $w)) {
                    $seen[$w] = true;
                }
                if (isset($words[$i + 1]) && ! in_array($w, self::STOP, true) && ! in_array($words[$i + 1], self::STOP, true)) {
                    $seen[$w.' '.$words[$i + 1]] = true;
                }
            }
            foreach (array_keys($seen) as $phrase) {
                $counts[$phrase] = ($counts[$phrase] ?? 0) + $n;
            }
        }
        // a pair said as often as its words is the phrase ("שמלה לחתונה", not "שמלה" and "לחתונה"): on the same count the
        // longer first, and its words alone are then not said again
        // a pair said in most of its words' searches ("linen dress" 235 of "linen" 236) stands for them
        foreach ($counts as $phrase => $n) {
            $parts = explode(' ', (string) $phrase);
            if (count($parts) === 2 && $n >= 0.8 * min($counts[$parts[0]] ?? $n, $counts[$parts[1]] ?? $n)) {
                foreach ($parts as $part) {
                    if (isset($counts[$part]) && $counts[$part] <= $n / 0.8) {
                        $counts[$part] = 0;
                    }
                }
            }
        }
        uksort($counts, fn ($a, $b): int => [$counts[$b], substr_count((string) $b, ' '), (string) $a] <=> [$counts[$a], substr_count((string) $a, ' '), (string) $b]);
        $out = [];
        foreach ($counts as $phrase => $n) {
            if ($n < 2 || count($out) >= $limit) {
                continue;
            }
            $phrase = (string) $phrase;
            $inside = array_filter($out, fn (array $o): bool => str_contains(' '.$o['phrase'].' ', ' '.$phrase.' ') || str_contains(' '.$phrase.' ', ' '.$o['phrase'].' '));
            if ($inside === []) {
                $out[] = ['phrase' => $phrase, 'n' => (int) $n];
            }
        }

        return $out;
    }

    public function tryIt(Shop $shop, string $words, int $limit): array
    {
        $words = mb_substr(trim($words), 0, 200);
        if ($words === '') {
            return ['total' => 0, 'products' => []];
        }
        try {
            $result = app(SearchCatalog::class)->handle((string) $shop->id, $words, ['product'], false, $limit);
        } catch (Throwable $e) {
            report($e);

            return ['total' => 0, 'products' => []];
        }
        $found = array_slice((array) ($result['groups']['product'] ?? []), 0, $limit);
        $ids = array_map(fn ($p): string => preg_replace('/^p:/', '', (string) (is_array($p) ? ($p['id'] ?? '') : '')), $found);
        $products = CatalogProduct::query()->whereIn('external_id', $ids)->get(['external_id', 'title', 'image_url', 'price', 'url'])->keyBy('external_id');
        $currency = (string) ($shop->currency ?: 'ILS');

        return [
            'total' => (int) ($result['total'] ?? count($found)),
            'products' => array_values(array_filter(array_map(function (string $id) use ($products, $currency): ?array {
                $p = $products[$id] ?? null;

                return $p === null ? null : [
                    'title' => (string) $p->title,
                    'picture' => $p->image_url,
                    'price' => $p->price === null ? null : Money::total((float) $p->price, $currency),
                    'url' => $p->url,
                ];
            }, $ids))),
        ];
    }

    /**
     * The product opened most after each word, in the period.
     *
     * @param  list<string>  $queries
     * @return array<string, array{title: string, picture: string|null}>
     */
    private function clicked(array $queries, CarbonInterface $since, CarbonInterface $until): array
    {
        $best = [];
        foreach (SearchClick::query()->whereIn('query', $queries)->where('day', '>=', $since->toDateString())->where('day', '<', $this->end($until))
            ->select('query', 'item')->selectRaw('MAX(title) as title, SUM(clicks) as clicks')->groupBy('query', 'item')->get() as $row) {
            $q = (string) $row->query;
            if (! isset($best[$q]) || (int) $row->clicks > $best[$q]['clicks']) {
                $best[$q] = ['item' => (string) $row->item, 'title' => (string) $row->title, 'clicks' => (int) $row->clicks];
            }
        }
        $products = CatalogProduct::query()->whereIn('external_id', array_map(fn (array $b): string => preg_replace('/^p:/', '', $b['item']), $best))->get(['external_id', 'title', 'image_url'])->keyBy('external_id');

        return array_map(function (array $b) use ($products): array {
            $p = $products[preg_replace('/^p:/', '', $b['item'])] ?? null;

            return ['title' => (string) ($p?->title ?: $b['title']), 'picture' => $p?->image_url];
        }, $best);
    }

    /**
     * The orders of visits that searched each word in the period, each visit once per word.
     *
     * @param  list<string>  $queries  normalized
     * @return array<string, float>
     */
    private function sales(array $queries, CarbonInterface $since, CarbonInterface $until): array
    {
        $wanted = array_flip($queries);
        $visits = [];
        AnalyticsVisitStep::query()->where('kind', 'search')->where('at', '>=', $since)->where('at', '<', $until)->whereNotNull('label')
            ->select(['visit_id', 'label'])->orderBy('id')->chunk(2000, function ($steps) use ($wanted, &$visits): void {
                foreach ($steps as $step) {
                    $q = HebrewSearch::normalize((string) $step->label);
                    if (isset($wanted[$q])) {
                        $visits[$q][(string) $step->visit_id] = true;
                    }
                }
            });
        if ($visits === []) {
            return [];
        }
        $totals = AnalyticsVisit::query()->whereIn('id', array_keys(array_merge(...array_values($visits))))->where('preview', false)->where('order_total', '>', 0)->pluck('order_total', 'id');
        $out = [];
        foreach ($visits as $q => $ids) {
            $out[$q] = round(array_sum(array_map(fn (string $id): float => (float) ($totals[$id] ?? 0), array_keys($ids))), 2);
        }

        return $out;
    }

    private function terms(CarbonInterface $since, CarbonInterface $until): Builder
    {
        return SearchTerm::query()->where('day', '>=', $since->toDateString())->where('day', '<', $this->end($until));
    }

    private function end(CarbonInterface $until): string
    {
        return $until->copy()->subSecond()->addDay()->toDateString();
    }
}
