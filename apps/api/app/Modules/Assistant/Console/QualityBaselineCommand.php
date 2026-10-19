<?php

namespace App\Modules\Assistant\Console;

use App\Core\Tenancy\TenantContext;
use App\Modules\Assistant\Actions\BuildQualityBaseline;
use App\Modules\Assistant\Actions\RunQualityCases;
use App\Modules\Assistant\Models\AssistantQualityCase;
use App\Modules\Assistant\Models\AssistantQualityRun;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Console\Command;

/**
 * The quality baseline: real questions from every shop, kept with their data (build), and asked again
 * (run) to give every change a number: did it make answers better, worse, or only more expensive.
 * Building calls no model; every case run is a real, paid model call.
 */
final class QualityBaselineCommand extends Command
{
    protected $signature = 'assistant:quality-baseline
        {action : build or run}
        {--per-shop=20 : build: questions taken from each shop}
        {--shop=* : one or more shops, by slug or ID; every shop when left out}
        {--prompt= : run: the answer_site prompt version, the current one when left out}
        {--live : run: on today\'s catalogue and pages instead of each case\'s frozen data}
        {--label= : run: a name for the run, to find it again}';

    protected $description = 'Build the quality baseline from real questions, or run it and print its numbers against the run before.';

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

        $runs = [];
        foreach ($shops ?? [null] as $shopId) {
            $runs[] = $cases->handle($shopId, null, $this->option('prompt') === null ? null : (int) $this->option('prompt'),
                $this->option('live') ? AssistantQualityRun::LIVE : AssistantQualityRun::FROZEN, AssistantQualityCase::BASELINE, $this->option('label'));
        }

        foreach ($runs as $run) {
            $before = AssistantQualityRun::query()->where('id', '!=', $run->id)->where('kind', $run->kind)->where('mode', $run->mode)
                ->where('shop_id', $run->shop_id)->whereNotNull('finished_at')->where('id', '<', $run->id)->orderByDesc('id')->first();
            $t = (array) $run->totals;
            $b = (array) ($before->totals ?? []);
            $this->line(sprintf('%s v%s %s: ran %d · answered %s%% · supported %s%% · unanswered %s%% · $%.5f per answer%s',
                $run->label ?? $run->id, $run->prompt_version ?? '?', $run->mode, $t['ran'] ?? 0, $t['answered'] ?? '—', $t['supported'] ?? '—', $t['unanswered'] ?? '—',
                (float) ($t['cost_per_answer'] ?? 0), $b === [] ? '' : sprintf(' (before: %s%% · %s%% · %s%% · $%.5f)', $b['answered'] ?? '—', $b['supported'] ?? '—', $b['unanswered'] ?? '—', (float) ($b['cost_per_answer'] ?? 0))));
        }

        return self::SUCCESS;
    }
}
