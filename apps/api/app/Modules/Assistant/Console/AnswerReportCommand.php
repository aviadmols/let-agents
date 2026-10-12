<?php

namespace App\Modules\Assistant\Console;

use App\Core\Tenancy\TenantContext;
use App\Modules\Assistant\Actions\AnswerSiteQuestion;
use App\Modules\Runs\Enums\RunStatus;
use App\Modules\Runs\Models\Run;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Console\Command;

/**
 * What answering shoppers' questions in the search box cost and how it went, by what the writing
 * model was given (full or dynamic context): tokens in and out, cost, time, how often the checker
 * changed an answer, and how often an answer was refused or failed. Evaluation runs are left out.
 * Questions answered before this was recorded show as "not recorded".
 *
 *   assistant:answer-report --days=14 --shop=rocks-and-gold
 */
final class AnswerReportCommand extends Command
{
    protected $signature = 'assistant:answer-report {--days=7 : how many days back} {--shop= : one shop, by slug or ID}';

    protected $description = 'Tokens, cost, time, checker changes and refusals of search box answers, by context mode.';

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

            $groups = [];

            foreach (Run::query()->where('action', AnswerSiteQuestion::ACTION)
                ->where('created_at', '>=', now()->subDays(max(1, (int) $this->option('days'))))
                ->when($shopId !== null, fn ($q) => $q->where('shop_id', $shopId))
                ->lazyById(500) as $run) {
                if (isset($run->input['evaluation'])) {
                    continue;
                }

                $output = (array) $run->output;
                $mode = (string) ($output['context']['mode'] ?? 'not recorded');
                $groups[$mode] ??= ['runs' => 0, 'failed' => 0, 'refused' => 0, 'in' => [], 'out' => [], 'cost' => [], 'ms' => [], 'checked' => 0, 'changed' => 0];
                $g = &$groups[$mode];
                $g['runs']++;

                if ($run->status !== RunStatus::Succeeded) {
                    $g['failed'] += $run->summary_key === 'assistant::runs.model_failed' ? 1 : 0;
                    unset($g);

                    continue;
                }

                $writer = (array) ($output['writer'] ?? []);
                $check = (array) ($output['check'] ?? []);

                if ($writer !== []) {
                    $g['in'][] = (int) $writer['in'] + (int) ($check['in'] ?? 0);
                    $g['out'][] = (int) $writer['out'] + (int) ($check['out'] ?? 0);
                    $g['cost'][] = (float) $writer['cost_usd'] + (float) ($check['cost_usd'] ?? 0);
                    $g['ms'][] = (int) $writer['ms'] + (int) ($check['ms'] ?? 0);
                }

                if (($check['ran'] ?? false) === true) {
                    $g['checked']++;
                    $g['changed'] += ($check['verdict'] ?? 'kept') !== 'kept' ? 1 : 0;
                }

                $g['refused'] += ($output['refused'] ?? null) !== null ? 1 : 0;
                unset($g);
            }

            if ($groups === []) {
                $this->line('No questions in this period.');

                return self::SUCCESS;
            }

            $avg = fn (array $values, int $decimals = 0): string => $values === [] ? '—' : (string) round(array_sum($values) / count($values), $decimals);
            $rate = fn (int $part, int $whole): string => $whole === 0 ? '—' : round(100 * $part / $whole, 1).'%';

            $this->table(['context', 'questions', 'avg in', 'avg out', 'avg cost $', 'avg ms', 'checker changed', 'refused', 'failed'],
                array_map(fn (string $mode, array $g): array => [
                    $mode, $g['runs'], $avg($g['in']), $avg($g['out']), $avg($g['cost'], 6), $avg($g['ms']),
                    $rate($g['changed'], $g['checked']), $rate($g['refused'], $g['runs']), $rate($g['failed'], $g['runs']),
                ], array_keys($groups), $groups));

            return self::SUCCESS;
        });
    }
}
