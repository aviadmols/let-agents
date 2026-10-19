<?php

namespace App\Modules\Search\Support;

use App\Core\Facades\Settings;
use App\Modules\Admin\Contracts\ReportRow;
use App\Modules\Admin\Contracts\ReportSeries;
use App\Modules\Admin\Contracts\ShopReport;
use App\Modules\Analytics\Models\BlockedIp;
use App\Modules\Search\Models\SearchLog;
use App\Modules\Search\Models\SearchSynonym;
use App\Modules\Search\Models\SearchTerm;
use App\Modules\Tenancy\Models\Shop;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The search's part of the shop's analytics screen: how many searched (by photo too), the words most
 * searched with how many adds to the cart ten searches bring, the searches that found nothing (each can
 * become a synonym there), and the ones that found products nobody clicked. Counts from search_terms.
 */
final class SearchReport implements ShopReport
{
    private const PHOTO = '[photo]';

    public function figures(Shop $shop, CarbonInterface $since, CarbonInterface $until): array
    {
        $terms = $this->terms($since, $until);

        return [
            'searches' => (int) (clone $terms)->where('query', '!=', self::PHOTO)->sum('searches'),
            'photo_searches' => (int) (clone $terms)->where('query', self::PHOTO)->sum('searches'),
            'ips' => ['over' => (int) Settings::get('analytics.ip_alert_per_day', $shop->id)],
            'series' => ['searches' => $this->series($since, $until)],
        ];
    }

    /**
     * Searches through the period at the chart's scale (ReportSeries), by photo too: by the hour from
     * each search's own line (search_logs), by the day or the month from the day's counts.
     *
     * @return array<string, int>
     */
    private function series(CarbonInterface $since, CarbonInterface $until): array
    {
        ['unit' => $unit, 'format' => $format] = ReportSeries::scale($since, $until);

        if ($unit === 'hour') {
            return SearchLog::query()->where('created_at', '>=', $since)->where('created_at', '<', $until)->get(['created_at'])
                ->countBy(fn (SearchLog $log): string => ReportSeries::key($log->created_at, $format))->all();
        }

        $counts = [];
        foreach ((clone $this->terms($since, $until))->reorder()->selectRaw('day, sum(searches) as n')->groupBy('day')->pluck('n', 'day') as $day => $n) {
            $key = Carbon::parse((string) $day)->format($format);
            $counts[$key] = ($counts[$key] ?? 0) + (int) $n;
        }

        return $counts;
    }

    public function lists(): array
    {
        return ['words', 'empty', 'noclick', 'ips'];
    }

    public function rows(Shop $shop, string $list, CarbonInterface $since, CarbonInterface $until, int $limit, string $search = '', string $sort = 'value'): array
    {
        if ($list === 'ips') {
            return $this->addresses($shop, $since, $until, $limit, $search, $sort);
        }

        $grouped = $this->terms($since, $until)->where('query', '!=', self::PHOTO)
            ->select('query')
            ->selectRaw('SUM(searches) as searches, SUM(empty) as empty, SUM(clicks) as clicks, SUM(added) as added, MAX(last_results) as results, MAX(day) as last_day')
            ->groupBy('query');

        if (($words = mb_strtolower(trim($search))) !== '') {
            $grouped->where('query', 'like', '%'.$words.'%');
        }

        match ($list) {
            'empty' => $grouped->havingRaw('SUM(empty) > 0'),
            'noclick' => $grouped->havingRaw('MAX(last_results) > 0')->havingRaw('SUM(clicks) * 10 < SUM(searches)')->havingRaw('SUM(searches) >= 2'),
            default => null,
        };

        $value = $list === 'empty' ? 'SUM(empty)' : 'SUM(searches)';
        $total = DB::query()->fromSub(clone $grouped, 'g')->count();
        $sort === 'name' ? $grouped->orderBy('query') : $grouped->orderByDesc(DB::raw($value))->orderBy('query');
        $found = $grouped->limit($limit)->get();
        $people = $list === 'empty' ? $this->people($found->pluck('query')->all(), $since, $until) : [];

        $rows = $found->map(function ($t) use ($list, $people): ReportRow {
            $searches = (int) $t->searches;
            $q = (string) $t->query;

            return match ($list) {
                'empty' => new ReportRow(
                    key: $q, title: $q, value: (int) $t->empty,
                    sub: implode(' · ', array_filter([
                        __('search::report.searches', ['n' => number_format((int) $t->empty)]),
                        __('search::report.last', ['when' => self::when((string) $t->last_day)]),
                        ($people[$q] ?? 0) > 1 ? __('search::report.people', ['n' => $people[$q]]) : null,
                    ])),
                    actions: ['synonym'],
                ),
                'noclick' => new ReportRow(
                    key: $q, title: $q, value: $searches,
                    sub: __('search::report.searches', ['n' => number_format($searches)]).' · '.__('search::report.results', ['n' => (int) $t->results]).' · '.trans_choice('search::report.clicks', (int) $t->clicks, ['n' => (int) $t->clicks]),
                ),
                default => new ReportRow(
                    key: $q, title: $q, value: $searches,
                    sub: __('search::report.adds_per_ten', ['n' => $searches > 0 ? number_format(10 * (int) $t->added / $searches, 1) : '0']),
                    pill: (int) $t->empty > 0 ? __('search::report.empty_pill', ['n' => number_format((int) $t->empty)]) : null,
                    tone: (int) $t->empty > 0 ? 'warn' : '',
                ),
            };
        })->all();

        return ['rows' => $rows, 'total' => $total];
    }

    public function act(Shop $shop, string $list, string $key, string $action, string $value = ''): ?string
    {
        if ($list === 'ips' && in_array($action, ['block', 'unblock'], true) && filter_var($key, FILTER_VALIDATE_IP) !== false) {
            $action === 'block'
                ? BlockedIp::query()->firstOrCreate(['ip' => $key], ['shop_id' => $shop->id, 'blocked_by' => auth()->id()])
                : BlockedIp::query()->where('ip', $key)->delete();
            BlockedIp::forget((string) $shop->id);

            return __('search::report.'.($action === 'block' ? 'blocked' : 'unblocked'), ['ip' => $key]);
        }

        if ($action !== 'synonym' || ! in_array($list, ['empty', 'words', 'noclick'], true)) {
            return null;
        }

        $term = mb_substr(trim($key), 0, 80);
        $means = mb_substr(trim($value), 0, 120);

        if (HebrewSearch::normalize($term) === '' || HebrewSearch::normalize($means) === '' || HebrewSearch::normalize($term) === HebrewSearch::normalize($means)) {
            return __('search::ui.synonyms.invalid');
        }

        SearchSynonym::query()->firstOrCreate(['term' => $term, 'means' => $means], ['shop_id' => $shop->id, 'origin' => 'team']);

        return __('search::report.synonym_added', ['term' => $term, 'means' => $means]);
    }

    /**
     * Addresses that searched more than the shop's line in a day, and the ones the shop blocked: each
     * with its busiest day, its searches and clicks, how many days in a row, and code's word on it
     * (many searches and no click is not a shopper).
     *
     * @return array{rows: list<ReportRow>, total: int}
     */
    private function addresses(Shop $shop, CarbonInterface $since, CarbonInterface $until, int $limit, string $search, string $sort): array
    {
        $line = max(1, (int) Settings::get('analytics.ip_alert_per_day', $shop->id));
        $days = SearchLog::query()->whereNotNull('ip')->where('created_at', '>=', $since)->where('created_at', '<', $until)
            ->selectRaw('ip, date(created_at) as d, count(*) as n, sum(case when clicked is not null then 1 else 0 end) as clicks')
            ->groupBy('ip', DB::raw('date(created_at)'))->havingRaw('count(*) >= ?', [$line])->get();
        $blocked = BlockedIp::query()->get(['ip', 'created_at'])->keyBy('ip');
        $by = [];

        foreach ($days as $day) {
            $ip = (string) $day->ip;
            $by[$ip] ??= ['top' => 0, 'searches' => 0, 'clicks' => 0, 'days' => []];
            $by[$ip]['top'] = max($by[$ip]['top'], (int) $day->n);
            $by[$ip]['searches'] += (int) $day->n;
            $by[$ip]['clicks'] += (int) $day->clicks;
            $by[$ip]['days'][] = (string) $day->d;
        }

        foreach ($blocked as $ip => $row) {
            $by[(string) $ip] ??= ['top' => 0, 'searches' => 0, 'clicks' => 0, 'days' => []];
        }

        $rows = [];

        foreach ($by as $ip => $seen) {
            if ($search !== '' && ! str_starts_with($ip, trim($search))) {
                continue;
            }

            $isBlocked = isset($blocked[$ip]);
            $bot = $seen['top'] >= $line && $seen['clicks'] === 0;
            $rows[] = new ReportRow(
                key: $ip, title: $ip, value: $seen['top'], ltr: true,
                sub: implode(' · ', array_filter([
                    $seen['top'] > 0 ? __('search::report.ip_top', ['n' => number_format($seen['top'])]) : null,
                    $seen['searches'] > 0 ? __('search::report.ip_split', ['searches' => number_format($seen['searches']), 'clicks' => number_format($seen['clicks'])]) : null,
                    count($seen['days']) > 1 ? __('search::report.ip_days', ['n' => self::streak($seen['days'])]) : null,
                    $bot ? __('search::report.ip_bot') : null,
                    $isBlocked ? __('search::report.ip_blocked_on', ['date' => $blocked[$ip]->created_at->timezone('Asia/Jerusalem')->format('j.n')]) : null,
                ])),
                pill: $isBlocked ? __('search::report.ip_blocked') : ($bot || $seen['top'] >= 2 * $line ? __('search::report.ip_unusual') : __('search::report.ip_high')),
                tone: $isBlocked ? '' : ($bot || $seen['top'] >= 2 * $line ? 'bad' : 'warn'),
                actions: [$isBlocked ? 'unblock' : 'block'],
            );
        }

        usort($rows, fn (ReportRow $a, ReportRow $b): int => $sort === 'name' ? strcmp($a->title, $b->title) : [$b->value, $a->title] <=> [$a->value, $b->title]);

        return ['rows' => array_slice($rows, 0, $limit), 'total' => count($rows)];
    }

    /** @param list<string> $days the longest run of days one after another */
    private static function streak(array $days): int
    {
        sort($days);
        $best = $run = 1;

        for ($i = 1; $i < count($days); $i++) {
            $run = Carbon::parse($days[$i - 1])->addDay()->toDateString() === $days[$i] ? $run + 1 : 1;
            $best = max($best, $run);
        }

        return $best;
    }

    /** @return Builder<SearchTerm> */
    private function terms(CarbonInterface $since, CarbonInterface $until): Builder
    {
        return SearchTerm::query()->where('day', '>=', $since->toDateString())->where('day', '<', $until->copy()->subSecond()->addDay()->toDateString());
    }

    /**
     * How many different shoppers (sessions) searched each word and found nothing.
     *
     * @param  list<string>  $queries
     * @return array<string, int>
     */
    private function people(array $queries, CarbonInterface $since, CarbonInterface $until): array
    {
        if ($queries === []) {
            return [];
        }

        return SearchLog::query()->whereIn('query', $queries)->where('results', 0)->whereNotNull('session_id')
            ->where('created_at', '>=', $since)->where('created_at', '<', $until)
            ->selectRaw('query, count(distinct session_id) as n')->groupBy('query')->pluck('n', 'query')
            ->map(fn ($n): int => (int) $n)->all();
    }

    private static function when(string $day): string
    {
        $date = Carbon::parse($day);

        return match (true) {
            $date->isToday() => __('search::report.today'),
            $date->isYesterday() => __('search::report.yesterday'),
            $date->gt(now()->subWeek()) => __('search::report.days_ago', ['n' => (int) $date->startOfDay()->diffInDays(now()->startOfDay())]),
            default => $date->format('j.n'),
        };
    }
}
