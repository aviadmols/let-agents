<?php

namespace App\Modules\Runs\Support;

use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Enums\RunStatus;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Runs\Models\Run;
use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Throwable;

final class RunRecorder implements RecordsRuns
{
    private const MAX_ERROR_CHARS = 2000;

    public function track(
        string $agent,
        string $action,
        Closure $work,
        ?string $shopId = null,
        array $input = [],
        RunTrigger $trigger = RunTrigger::Manual,
        ?string $parentId = null,
    ): Run {
        $startedAt = now();
        $clock = hrtime(true);

        $run = Run::query()->create([
            'shop_id' => $shopId,
            'parent_id' => $parentId,
            'agent' => $agent,
            'action' => $action,
            'status' => RunStatus::Running,
            'trigger' => $trigger,
            'user_id' => Auth::id(),
            'input' => Run::redact($input),
            'started_at' => $startedAt,
        ]);

        $context = new RunContext($run->id);
        $status = RunStatus::Succeeded;
        $exception = null;

        try {
            $work($context);

            if ($context->hasFailed()) {
                $status = RunStatus::Failed;
            }
        } catch (Throwable $e) {
            $status = RunStatus::Failed;
            $exception = $e;
            report($e);
        }

        $reportData = $context->report();
        $usage = $reportData['usage'];

        $run->forceFill([
            'status' => $status,
            'summary_key' => $reportData['summary_key'] ?? ($exception ? 'runs::runs.summaries.unexpected_error' : 'runs::runs.summaries.done'),
            'summary_params' => $reportData['summary_params'],
            'output' => $reportData['output'] === [] ? null : Run::redact($reportData['output']),
            'error' => $exception
                ? Str::limit(class_basename($exception).': '.$exception->getMessage(), self::MAX_ERROR_CHARS)
                : ($reportData['error'] === null ? null : Str::limit($reportData['error'], self::MAX_ERROR_CHARS)),
            'provider' => $usage['provider'] ?? null,
            'model' => $usage['model'] ?? null,
            'input_tokens' => $usage['input_tokens'] ?? 0,
            'output_tokens' => $usage['output_tokens'] ?? 0,
            'cache_read_tokens' => $usage['cache_read_tokens'] ?? 0,
            'cost_usd' => $usage['cost_usd'] ?? null,
            'finished_at' => now(),
            'duration_ms' => (int) round((hrtime(true) - $clock) / 1_000_000),
        ])->save();

        // Each model call in the ledger, so spend reads by shop, agent and model.
        if ($reportData['calls'] !== []) {
            foreach ($reportData['calls'] as $call) {
                Run::recordUsage($run->shop_id, $run->agent, $run->action, $call['provider'], $call['model'], $call['input_tokens'], $call['output_tokens'], (float) ($call['cost_usd'] ?? 0), $run->id, $call['cache_read_tokens']);
            }
        }

        return $run;
    }
}
