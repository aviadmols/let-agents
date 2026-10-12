<?php

namespace App\Modules\Assistant\Console;

use App\Core\Tenancy\TenantContext;
use App\Modules\Assistant\Actions\AnswerSiteQuestion;
use App\Modules\Runs\Enums\RunStatus;
use App\Modules\Runs\Models\Run;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Console\Command;

/**
 * What the checker of search answers did, by kind of question and by checker model: how often it
 * ran, how often it changed or refused an answer, what it cost and how long it took. Read before
 * deciding where a check may be skipped.
 *
 *   assistant:checker-report                 the last 7 days, every shop
 *   assistant:checker-report --days=30 --shop=rocks-and-gold
 */
final class CheckerReportCommand extends Command
{
    protected $signature = 'assistant:checker-report {--days=7 : how many days back} {--shop= : one shop, by slug or ID}';

    protected $description = 'How often the search answer checker changes answers, by kind of question, with its cost and time.';

    public function handle(TenantContext $tenant): int
    {
        return $tenant->runUnscoped(function (): int {
            $shopId = null;

            if ($this->option('shop')) {
                $shopId = Shop::query()->where('slug', (string) $this->option('shop'))->orWhere('id', (string) $this->option('shop'))->value('id');

                if ($shopId === null) {
                    $this->error('Shop not found.');

                    return self::FAILURE;
                }
            }

            $runs = Run::query()
                ->where('action', AnswerSiteQuestion::ACTION)
                ->where('created_at', '>=', now()->subDays(max(1, (int) $this->option('days'))))
                ->when($shopId !== null, fn ($q) => $q->where('shop_id', $shopId));

            $groups = [];
            $failed = 0;

            foreach ($runs->lazyById(500) as $run) {
                // Fixed questions asked by assistant:context-eval are not shoppers'.
                if (isset($run->input['evaluation'])) {
                    continue;
                }

                if ($run->status !== RunStatus::Succeeded) {
                    $failed += $run->summary_key === 'assistant::runs.model_failed' ? 1 : 0;

                    continue;
                }

                $check = (array) ($run->output['check'] ?? []);

                if (! isset($check['type'])) {
                    continue;
                }

                $key = $check['type'].' · '.($check['model'] ?? '—');
                $groups[$key] ??= ['questions' => 0, 'skipped' => 0, 'kept' => 0, 'picks_removed' => 0, 'answer_removed' => 0, 'rejected' => 0, 'cost' => [], 'ms' => []];
                $group = &$groups[$key];
                $group['questions']++;

                if (($check['ran'] ?? false) !== true) {
                    $group['skipped']++;
                } else {
                    $group[$check['verdict'] ?? 'kept']++;
                    $group['cost'][] = (float) ($check['cost_usd'] ?? 0);
                    $group['ms'][] = (int) ($check['ms'] ?? 0);
                }

                unset($group);
            }

            if ($groups === []) {
                $this->line('No checked questions in this period.');

                return self::SUCCESS;
            }

            ksort($groups);
            $rows = [];

            foreach ($groups as $key => $g) {
                $checked = count($g['cost']);
                $changed = $g['picks_removed'] + $g['answer_removed'] + $g['rejected'];
                sort($g['ms']);

                $rows[] = [
                    $key,
                    $g['questions'],
                    $checked,
                    $g['skipped'],
                    $g['kept'],
                    $g['picks_removed'],
                    $g['answer_removed'],
                    $g['rejected'],
                    $checked > 0 ? round(100 * $changed / $checked, 1).'%' : '—',
                    $checked > 0 ? '$'.number_format(array_sum($g['cost']) / $checked, 6) : '—',
                    $checked > 0 ? (int) round(array_sum($g['ms']) / $checked) : '—',
                    $checked > 0 ? $g['ms'][(int) floor(0.95 * ($checked - 1))] : '—',
                ];
            }

            $this->table(['kind · checker', 'questions', 'checked', 'skipped', 'kept', 'picks removed', 'answer removed', 'refused', 'changed', 'avg cost', 'avg ms', 'p95 ms'], $rows);

            if ($failed > 0) {
                $this->warn("{$failed} questions failed on a model call (the writer or the checker).");
            }

            return self::SUCCESS;
        });
    }
}
