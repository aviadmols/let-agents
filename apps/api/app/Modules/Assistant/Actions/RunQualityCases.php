<?php

namespace App\Modules\Assistant\Actions;

use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\SpendCapReached;
use App\Modules\Assistant\Models\AssistantQualityCase;
use App\Modules\Assistant\Support\QualityExpect;
use Throwable;

/**
 * A shop's regression cases asked again (a replay each, with the writer prompt at a chosen version or
 * the current one) and checked in code against what the team expects; each case keeps its last run.
 * Every replay is a real model call, recorded and paid like any other: run before a change goes out,
 * not on a schedule. Stops at the spending cap and says so.
 */
final class RunQualityCases
{
    public function __construct(
        private readonly AnswerSiteQuestion $answers,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @param  list<string>|null  $ids  some cases only; null runs all of the shop's
     * @return array{ran: int, passed: int, failed: int, stopped: string|null, cost_usd: float}
     */
    public function handle(string $shopId, ?array $ids = null, ?int $answerVersion = null): array
    {
        $cases = $this->tenant->run($shopId, fn () => AssistantQualityCase::query()->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->orderBy('created_at')->get());
        $totals = ['ran' => 0, 'passed' => 0, 'failed' => 0, 'stopped' => null, 'cost_usd' => 0.0];

        foreach ($cases as $case) {
            try {
                $out = $this->answers->replay($shopId, $case->question, (array) $case->products, $answerVersion);
            } catch (SpendCapReached) {
                $totals['stopped'] = 'spend_cap';
                break;
            } catch (Throwable $e) {
                report($e);
                $totals['stopped'] = 'error';
                break;
            }

            $problems = QualityExpect::problems((array) $case->expect, $out['result']);
            $cost = (float) ($out['answered']['cost_usd'] ?? 0);

            $this->tenant->run($shopId, fn () => $case->forceFill([
                'last_run_at' => now(),
                'last_passed' => $problems === [],
                'last_problems' => $problems,
                'last_answer' => mb_substr((string) ($out['result']['answer'] ?? ''), 0, 2000),
                'last_outcome' => mb_substr((string) ($out['result']['outcome'] ?? ''), 0, 40),
                'last_prompt_version' => $out['answered']['prompt_version'] ?? null,
                'last_cost_usd' => $cost,
            ])->save());

            $totals['ran']++;
            $totals[$problems === [] ? 'passed' : 'failed']++;
            $totals['cost_usd'] += $cost;
        }

        $totals['cost_usd'] = round($totals['cost_usd'], 6);

        return $totals;
    }
}
