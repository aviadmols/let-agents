<?php

namespace App\Modules\Enrichment\Actions;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\BatchChatModel;
use App\Modules\Ai\Contracts\BatchState;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Enrichment\Enums\BatchStatus;
use App\Modules\Enrichment\Enums\ItemStatus;
use App\Modules\Enrichment\Enums\TaskType;
use App\Modules\Enrichment\Events\FactsDecided;
use App\Modules\Enrichment\Models\EnrichmentBatch;
use App\Modules\Enrichment\Models\EnrichmentBatchItem;
use App\Modules\Enrichment\Support\ReadingPrices;
use App\Modules\Enrichment\Tasks\TaskRegistry;
use App\Modules\Retrieval\Contracts\RefreshesVectors;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Models\Run;

/**
 * Reads the answers of batches handed to a provider's batch API, every so often. A batch still
 * being worked on is left alone; one that ended has each answer put through the same task checks
 * as an answer that came one at a time, and each is written to the ledger at the batch price; one
 * the provider lost (failed, expired) goes back to waiting, and the night hands it over again.
 *
 * Nothing is recorded when no batch was waiting: a quiet ten minutes is not a run.
 */
final class CollectProviderBatches
{
    public const AGENT = 'enrichment.nightly_model';

    public const ACTION = 'enrichment.collect_batches';

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
        private readonly TaskRegistry $tasks,
        private readonly BatchChatModel $batches,
    ) {}

    public function handle(string $shopId): ?Run
    {
        $waiting = $this->tenant->run($shopId, fn () => EnrichmentBatch::query()
            ->whereNotNull('provider_batch_id')
            ->where('status', BatchStatus::AwaitingResults)
            ->orderBy('submitted_at')
            ->get());

        if ($waiting->isEmpty()) {
            return null;
        }

        $readings = 0;
        $applied = 0;

        $run = $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            input: ['batches' => $waiting->count()],
            work: function (RunContext $run) use ($shopId, $waiting, &$readings, &$applied): void {
                $counts = ['collected' => 0, 'running' => 0, 'lost' => 0, 'applied' => 0, 'rejected' => 0, 'stale' => 0, 'errors' => []];

                foreach ($waiting as $batch) {
                    $provider = AiProviderName::from((string) $batch->provider);

                    try {
                        $state = $this->batches->state($provider, (string) $batch->provider_batch_id);
                    } catch (ModelCallFailed $e) {
                        $counts['errors'][] = $e->reason;

                        continue;
                    }

                    if ($state->status === BatchState::RUNNING) {
                        $counts['running']++;

                        continue;
                    }

                    if ($state->status === BatchState::LOST) {
                        // Its questions stay pending: the night hands them over again.
                        $batch->forceFill(['provider_batch_id' => null, 'submitted_at' => null])->save();
                        $counts['lost']++;

                        continue;
                    }

                    try {
                        // By reference: an arrow function would count into a copy.
                        $this->tenant->run($shopId, function () use ($run, $batch, $provider, &$counts): void {
                            $this->apply($run, $batch, $provider, $counts);
                        });
                        $counts['collected']++;
                        $readings += $batch->task === TaskType::FactReview ? 0 : 1;
                    } catch (ModelCallFailed $e) {
                        $counts['errors'][] = $e->reason;
                    }
                }

                $applied = (int) $counts['applied'];
                $run->output($counts)->summary('enrichment::runs.batches_collected', [
                    'collected' => (string) $counts['collected'],
                    'applied' => number_format($counts['applied']),
                    'rejected' => number_format($counts['rejected']),
                    'running' => (string) $counts['running'],
                    'lost' => (string) $counts['lost'],
                ]);
            },
        );

        // The same day, not the next night: a reading's answers go to the other family's review at once
        // (one review batch at a time, as the night would), and what was written or decided reaches
        // search: its index now, its vectors a few minutes later.
        if ($readings > 0 && Features::enabled('enrichment.auto_review', $shopId)) {
            app(RunNightlyReview::class)->handle($shopId);
        }

        if ($applied > 0) {
            event(new FactsDecided($shopId));
            app(RefreshesVectors::class)->soon($shopId);
        }

        return $run;
    }

    /** @param array<string, mixed> $counts */
    private function apply(RunContext $run, EnrichmentBatch $batch, AiProviderName $provider, array &$counts): void
    {
        $task = $this->tasks->for($batch->task);
        $model = (string) $batch->model;
        $items = EnrichmentBatchItem::query()->where('batch_id', $batch->id)->where('status', ItemStatus::Pending)->get()->keyBy('custom_id');
        $sent = $items->map(fn (EnrichmentBatchItem $item): string => (string) json_encode($item->request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))->all();
        $in = ReadingPrices::input($batch->task);
        $out = ReadingPrices::output($batch->task);

        foreach ($this->batches->results($provider, $model, (string) $batch->provider_batch_id, (string) $batch->system_prompt, $sent) as $result) {
            $item = $items->get($result->customId);

            if ($item === null) {
                continue;
            }

            if ($result->reply === null) {
                $item->forceFill(['status' => ItemStatus::Rejected, 'problems' => [$result->error ?? 'no_answer']])->save();
                $counts['rejected']++;

                continue;
            }

            $run->usage($provider->value, $model, $result->reply->inputTokens, $result->reply->outputTokens, 0, $result->reply->costUsd($in, $out) * BatchChatModel::DISCOUNT, true);

            // The answer is for the text the question was built from; a subject that changed since is not read from it.
            if ($task->isStale($batch, $item)) {
                $item->forceFill(['status' => ItemStatus::Stale, 'result' => $result->reply->data])->save();
                $counts['stale']++;

                continue;
            }

            $outcome = $task->apply($batch, $item, $result->reply->data, $model);
            $item->forceFill(['status' => $outcome->status, 'result' => $result->reply->data, 'problems' => $outcome->problems])->save();
            $counts[$outcome->status === ItemStatus::Applied ? 'applied' : 'rejected']++;
        }

        // Questions the provider never answered stay pending, and the night hands them over again.
        $pending = EnrichmentBatchItem::query()->where('batch_id', $batch->id)->where('status', ItemStatus::Pending)->count();
        $answered = EnrichmentBatchItem::query()->where('batch_id', $batch->id)->whereIn('status', [ItemStatus::Applied, ItemStatus::Rejected]);

        $batch->forceFill([
            'status' => $pending === 0 ? BatchStatus::Completed : BatchStatus::AwaitingResults,
            'provider_batch_id' => null,
            'result_count' => (clone $answered)->count(),
            'accepted_count' => (clone $answered)->where('status', ItemStatus::Applied)->count(),
            'rejected_count' => (clone $answered)->where('status', ItemStatus::Rejected)->count(),
            'completed_at' => $pending === 0 ? now() : null,
        ])->save();
    }
}
