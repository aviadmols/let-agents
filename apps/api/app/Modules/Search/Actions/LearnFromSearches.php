<?php

namespace App\Modules\Search\Actions;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Runs\Models\Run;
use App\Modules\Search\Models\SearchLearned;
use App\Modules\Search\Models\SearchSignal;
use App\Modules\Search\Support\HebrewSearch;
use Illuminate\Support\Facades\DB;

/**
 * What a store learns from its own shoppers' searches, every night, in code, for that store alone: the products
 * different shoppers opened and added to the cart for a search come first for it ("boost", newer weighing more), and a
 * word shoppers take off as a chip again and again is read no more as one ("no_chip", Mycolivia's "פטריה"). Only what
 * at least search.learn_min_visitors different shoppers did counts: one shopper, or a bot, teaches the store nothing.
 * Rewritten whole each night: what shoppers stopped doing goes with it.
 */
final class LearnFromSearches
{
    public const AGENT = 'search.learner';

    public const ACTION = 'search.learn';

    /** An add to the cart weighs this many opens. */
    private const ADD_WEIGHT = 3;

    /** A day this old weighs half as much as today. */
    private const HALF_LIFE_DAYS = 30;

    /** At most this many products first for one search. */
    private const BOOSTS_PER_SEARCH = 6;

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
    ) {}

    public function handle(string $shopId, RunTrigger $trigger = RunTrigger::Manual): ?Run
    {
        if (! Features::enabled('search.learning', $shopId)) {
            return null;
        }

        return $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            trigger: $trigger,
            input: [],
            work: fn (RunContext $run) => $this->tenant->run($shopId, fn () => $this->learn($run, $shopId)),
        );
    }

    private function learn(RunContext $run, string $shopId): void
    {
        $since = now()->subDays((int) Settings::get('search.learn_days', $shopId))->startOfDay();
        $min = max(1, (int) Settings::get('search.learn_min_visitors', $shopId));
        $signals = SearchSignal::query()->where('day', '>=', $since->toDateString())->get(['kind', 'query', 'value', 'visitors', 'day']);

        // the products opened and added for a search, by different shoppers, the newer weighing more
        $boosts = [];
        foreach ($signals->whereIn('kind', ['click', 'add']) as $s) {
            $key = $s->query."\n".$s->value;
            $age = max(0, $s->day->diffInDays(now()));
            $boosts[$key] ??= ['query' => $s->query, 'value' => $s->value, 'weight' => 0.0, 'visitors' => 0];
            $boosts[$key]['weight'] += $s->visitors * ($s->kind === 'add' ? self::ADD_WEIGHT : 1) * 0.5 ** ($age / self::HALF_LIFE_DAYS);
            $boosts[$key]['visitors'] += $s->visitors;
        }
        $boosts = array_filter($boosts, fn (array $b): bool => $b['visitors'] >= $min);
        $bySearch = [];
        foreach ($boosts as $b) {
            $bySearch[$b['query']][] = $b;
        }
        $kept = [];
        foreach ($bySearch as $list) {
            usort($list, fn (array $a, array $b): int => $b['weight'] <=> $a['weight']);
            array_push($kept, ...array_slice($list, 0, self::BOOSTS_PER_SEARCH));
        }

        // a word taken off as a chip by different shoppers, and more than twice as often as put on
        $off = [];
        $on = [];
        foreach ($signals->whereIn('kind', ['chip_off', 'chip_on']) as $s) {
            $word = HebrewSearch::normalize($s->value);
            if ($word === '') {
                continue;
            }
            if ($s->kind === 'chip_off') {
                $off[$word] = ($off[$word] ?? 0) + $s->visitors;
            } else {
                $on[$word] = ($on[$word] ?? 0) + $s->visitors;
            }
        }
        $noChips = array_keys(array_filter($off, fn (int $n, string $w): bool => $n >= $min && $n > 2 * ($on[$w] ?? 0), ARRAY_FILTER_USE_BOTH));

        DB::transaction(function () use ($shopId, $kept, $noChips, $off): void {
            SearchLearned::query()->delete();
            $now = now();
            $rows = [
                ...array_map(fn (array $b): array => ['kind' => 'boost', 'query' => $b['query'], 'value' => $b['value'], 'weight' => round($b['weight'], 4), 'visitors' => $b['visitors']], $kept),
                ...array_map(fn (string $w): array => ['kind' => 'no_chip', 'query' => '', 'value' => $w, 'weight' => 0, 'visitors' => $off[$w]], $noChips),
            ];
            foreach ($rows as $row) {
                SearchLearned::query()->create($row + ['shop_id' => $shopId, 'created_at' => $now, 'updated_at' => $now]);
            }
        });

        $run->output(['boosts' => count($kept), 'searches' => count($bySearch), 'no_chips' => $noChips, 'min_visitors' => $min,
            'sample' => array_map(fn (array $b): string => $b['query'].' → '.$b['value'].' ('.$b['visitors'].')', array_slice($kept, 0, 12))])
            ->summary('search::runs.learned', ['searches' => (string) count($bySearch), 'chips' => (string) count($noChips)]);
    }
}
