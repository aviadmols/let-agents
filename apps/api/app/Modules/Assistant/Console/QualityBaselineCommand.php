<?php

namespace App\Modules\Assistant\Console;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Assistant\Actions\BuildQualityBaseline;
use App\Modules\Assistant\Actions\RunQualityCases;
use App\Modules\Assistant\Models\AssistantQualityCase;
use App\Modules\Assistant\Models\AssistantQualityResult;
use App\Modules\Assistant\Models\AssistantQualityRun;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Console\Command;
use Throwable;

/**
 * The quality baseline: real questions from every shop, kept with their data (build), and asked again
 * (run) to give every change a number: did it make answers better, worse, faster, or only more expensive.
 * A change is tried on a run without touching the stores: another prompt version, or a setting set for
 * the run alone and put back after it. Two runs are read side by side (compare), case by case.
 * Building calls no model; every case run is a real, paid model call.
 */
final class QualityBaselineCommand extends Command
{
    protected $signature = 'assistant:quality-baseline
        {action : build, run or compare}
        {--per-shop=20 : build: questions taken from each shop}
        {--shop=* : one or more shops, by slug or ID; every shop when left out}
        {--prompt= : run: the answer_site prompt version, the current one when left out}
        {--scope-prompt= : run: the scope_site prompt version, the current one when left out}
        {--check-prompt= : run: the verify_site prompt version, the current one when left out}
        {--set=* : run: a setting for this run only, as key=value (assistant.site_check_effort=low); put back after}
        {--live : run: on today\'s catalogue and pages instead of each case\'s frozen data}
        {--label= : run: a name for the run, to find it again}
        {--runs= : compare: two runs, by ID or label, as a,b}';

    protected $description = 'Build the quality baseline from real questions, run it and print its numbers against the run before, or compare two runs case by case.';

    public function handle(BuildQualityBaseline $build, RunQualityCases $cases, TenantContext $tenant): int
    {
        $shops = null;
        if ($this->option('shop') !== []) {
            $shops = Shop::query()->whereIn('slug', (array) $this->option('shop'))->orWhereIn('id', (array) $this->option('shop'))->pluck('id')->map(fn ($id): string => (string) $id)->all();
        }

        if ($this->argument('action') === 'build') {
            $added = $build->handle((int) $this->option('per-shop'), $shops);
            $this->line(sprintf('%d questions added from %d shops; %d in the baseline.', array_sum($added), count($added),
                $tenant->runUnscoped(fn () => AssistantQualityCase::query()->where('kind', AssistantQualityCase::BASELINE)->count())));

            return self::SUCCESS;
        }

        if ($this->argument('action') === 'compare') {
            return $this->compare((string) $this->option('runs'), $tenant);
        }

        $versions = array_map('intval', array_filter(['scope_site' => $this->option('scope-prompt'), 'verify_site' => $this->option('check-prompt')], fn ($v): bool => $v !== null));
        $overrides = $this->overrides((array) $this->option('set'));

        if ($overrides === null) {
            return self::FAILURE;
        }

        $runs = [];
        $restore = $this->apply($overrides, $shops);

        try {
            foreach ($shops ?? [null] as $shopId) {
                $runs[] = $cases->handle($shopId, null, $this->option('prompt') === null ? null : (int) $this->option('prompt'),
                    $this->option('live') ? AssistantQualityRun::LIVE : AssistantQualityRun::FROZEN, AssistantQualityCase::BASELINE, $this->option('label'), null, $versions);
            }
        } finally {
            $restore();
        }

        foreach ($runs as $run) {
            $before = AssistantQualityRun::query()->where('id', '!=', $run->id)->where('kind', $run->kind)->where('mode', $run->mode)
                ->where('shop_id', $run->shop_id)->whereNotNull('finished_at')->where('id', '<', $run->id)->orderByDesc('id')->first();
            $t = (array) $run->totals;
            $b = (array) ($before->totals ?? []);
            $this->line(sprintf('%s v%s %s: ran %d · answered %s%% · supported %s%% · unanswered %s%% · $%.5f per answer%s',
                $run->label ?? $run->id, $run->prompt_version ?? '?', $run->mode, $t['ran'] ?? 0, $t['answered'] ?? '—', $t['supported'] ?? '—', $t['unanswered'] ?? '—',
                (float) ($t['cost_per_answer'] ?? 0), $b === [] ? '' : sprintf(' (before: %s%% · %s%% · %s%% · $%.5f)', $b['answered'] ?? '—', $b['supported'] ?? '—', $b['unanswered'] ?? '—', (float) ($b['cost_per_answer'] ?? 0))));
            $this->line(sprintf('  time: p50 %s ms · p90 %s ms · writer %s ms · checker %s ms · sent %s pieces, %s chars · nearness %s…%s · passed %d of %d expected',
                $t['p50_ms'] ?? '—', $t['p90_ms'] ?? '—', $t['writer_ms'] ?? '—', $t['check_ms'] ?? '—', $t['passages'] ?? '—', $t['chars'] ?? '—', $t['score_low'] ?? '—', $t['score_top'] ?? '—', $t['passed'] ?? 0, $t['expected'] ?? 0));
        }

        return self::SUCCESS;
    }

    /**
     * The settings for this run only, as key=value.
     *
     * @param  list<string>  $set
     * @return array<string, string>|null
     */
    private function overrides(array $set): ?array
    {
        $out = [];

        foreach ($set as $pair) {
            if (! str_contains($pair, '=')) {
                $this->error("--set takes key=value, not \"{$pair}\".");

                return null;
            }

            [$key, $value] = explode('=', $pair, 2);
            $out[trim($key)] = trim($value);
        }

        return $out;
    }

    /**
     * Sets each setting for the run, for the shops run (a global one once), and returns what puts them back: the
     * value each had before, or none.
     *
     * @param  array<string, string>  $overrides
     * @param  list<string>|null  $shops
     */
    private function apply(array $overrides, ?array $shops): callable
    {
        $before = [];

        foreach ($overrides as $key => $value) {
            foreach ($shops ?? [null] as $shopId) {
                try {
                    $was = Settings::overrideFor($key, $shopId);
                    Settings::set($key, $value, $shopId);
                    $before[] = [$key, $shopId, $was];
                } catch (Throwable $e) {
                    // a global setting is set once, whatever shops run
                    if ($shopId === null) {
                        throw $e;
                    }
                    $was = Settings::overrideFor($key);
                    Settings::set($key, $value);
                    $before[] = [$key, null, $was];

                    break;
                }
            }
            $this->line("  {$key} = {$value} for this run");
        }

        return function () use ($before): void {
            foreach (array_reverse($before) as [$key, $shopId, $was]) {
                $was === null ? Settings::clear($key, $shopId) : Settings::set($key, $was, $shopId);
            }
        };
    }

    /** Two runs side by side: their numbers, then every case whose outcome, verdict or pass changed, or whose time moved by half. */
    private function compare(string $pair, TenantContext $tenant): int
    {
        $keys = array_values(array_filter(array_map('trim', explode(',', $pair))));

        if (count($keys) !== 2) {
            $this->error('--runs takes two runs, by ID or label, as a,b.');

            return self::FAILURE;
        }

        $runs = array_map(fn (string $key): ?AssistantQualityRun => AssistantQualityRun::query()->where('id', $key)->orWhere('label', $key)->orderByDesc('id')->first(), $keys);

        if ($runs[0] === null || $runs[1] === null) {
            $this->error('No such run: '.($runs[0] === null ? $keys[0] : $keys[1]));

            return self::FAILURE;
        }

        [$a, $b] = $runs;
        $this->line(sprintf('%-16s %-22s %-22s', '', mb_substr((string) ($a->label ?? $a->id), 0, 22), mb_substr((string) ($b->label ?? $b->id), 0, 22)));
        foreach (['ran', 'answered', 'supported', 'unanswered', 'passed', 'expected', 'cost_per_answer', 'p50_ms', 'p90_ms', 'writer_ms', 'check_ms', 'writer_in', 'check_out', 'passages', 'chars', 'score_low', 'score_top'] as $key) {
            $this->line(sprintf('%-16s %-22s %-22s', $key, (string) (((array) $a->totals)[$key] ?? '—'), (string) (((array) $b->totals)[$key] ?? '—')));
        }

        $of = fn (AssistantQualityRun $run) => AssistantQualityResult::query()->where('run_id', $run->id)->get()->keyBy('case_id');
        $ra = $of($a);
        $rb = $of($b);
        $questions = $tenant->runUnscoped(fn () => AssistantQualityCase::query()->whereIn('id', $ra->keys()->merge($rb->keys())->unique()->all())->pluck('question', 'id'));
        $this->newLine();
        $changed = 0;

        foreach ($ra as $caseId => $x) {
            $y = $rb[$caseId] ?? null;

            if ($y === null) {
                continue;
            }

            $moved = $x->outcome !== $y->outcome || $x->verdict !== $y->verdict || $x->passed !== $y->passed
                || ($x->duration_ms && $y->duration_ms && abs($y->duration_ms - $x->duration_ms) > 0.5 * $x->duration_ms);

            if (! $moved) {
                continue;
            }

            $changed++;
            $pass = fn (?bool $p): string => $p === null ? '·' : ($p ? 'pass' : 'FAIL');
            $this->line(mb_substr((string) ($questions[$caseId] ?? $caseId), 0, 90));
            $this->line(sprintf('  %s/%s/%s %s ms → %s/%s/%s %s ms%s', $x->outcome, $x->verdict ?? '-', $pass($x->passed), $x->duration_ms ?? '?',
                $y->outcome, $y->verdict ?? '-', $pass($y->passed), $y->duration_ms ?? '?', $y->problems ? '  '.implode(' ', (array) $y->problems) : ''));
        }

        $this->line(sprintf('%d of %d cases changed.', $changed, $ra->count()));

        return self::SUCCESS;
    }
}
