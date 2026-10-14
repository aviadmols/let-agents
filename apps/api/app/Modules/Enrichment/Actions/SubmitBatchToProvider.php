<?php

namespace App\Modules\Enrichment\Actions;

use App\Core\Facades\Settings;
use App\Modules\Ai\Contracts\BatchChatModel;
use App\Modules\Ai\Contracts\BatchRequest;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\SpendCapReached;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Enrichment\Enums\BatchStatus;
use App\Modules\Enrichment\Enums\ItemStatus;
use App\Modules\Enrichment\Models\EnrichmentBatch;
use App\Modules\Enrichment\Models\EnrichmentBatchItem;
use App\Modules\Enrichment\Tasks\TaskRegistry;
use App\Modules\Runs\Contracts\RunContext;

/**
 * Hands a batch's questions to the provider's batch API in one go, instead of asking them one at
 * a time (AnswerBatchWithModel) or waiting for a file. The same questions and the same prompt;
 * the answers come back within a day at half the price, and CollectProviderBatches reads them
 * through the same checks in code.
 *
 * The month's cap is asked once for the whole batch, at the batch price, before anything is sent.
 */
final class SubmitBatchToProvider
{
    public const RUNNER = 'provider_batch';

    public function __construct(
        private readonly TaskRegistry $tasks,
        private readonly BatchChatModel $batches,
        private readonly SpendGuard $spend,
    ) {}

    /**
     * @return array{submitted: int, stale: int, stopped: ?string}
     */
    public function handle(RunContext $run, EnrichmentBatch $batch, string $model, AiProviderName $provider): array
    {
        $task = $this->tasks->for($batch->task);
        $maxOutput = (int) Settings::get('enrichment.model_answer_tokens', $batch->shop_id);
        $requests = [];
        $estimate = 0.0;
        $stale = 0;

        $items = EnrichmentBatchItem::query()->where('batch_id', $batch->id)->where('status', ItemStatus::Pending)->orderBy('custom_id')->get();

        foreach ($items as $item) {
            // A subject that changed while the batch waited is not asked about at all.
            if ($task->isStale($batch, $item)) {
                $item->forceFill(['status' => ItemStatus::Stale])->save();
                $stale++;

                continue;
            }

            $user = (string) json_encode($item->request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $requests[] = new BatchRequest($item->custom_id, (string) $batch->system_prompt, $user, $maxOutput);
            $estimate += ((mb_strlen((string) $batch->system_prompt) + mb_strlen($user)) / 3 * $this->price('input') + $maxOutput * $this->price('output')) / 1_000_000;
        }

        if ($requests === []) {
            return ['submitted' => 0, 'stale' => $stale, 'stopped' => null];
        }

        try {
            $this->spend->assertCanSpend($estimate * BatchChatModel::DISCOUNT);
            $id = $this->batches->submit($provider, $model, $requests);
        } catch (SpendCapReached) {
            return ['submitted' => 0, 'stale' => $stale, 'stopped' => 'spend_cap'];
        } catch (ModelCallFailed $e) {
            return ['submitted' => 0, 'stale' => $stale, 'stopped' => $e->reason];
        }

        $batch->forceFill([
            'runner' => self::RUNNER,
            'provider' => $provider->value,
            'provider_batch_id' => $id,
            'model' => $model,
            'submitted_at' => now(),
            'status' => BatchStatus::AwaitingResults,
        ])->save();

        $run->output(['provider_batch' => $id]);

        return ['submitted' => count($requests), 'stale' => $stale, 'stopped' => null];
    }

    private function price(string $name): float
    {
        return (float) Settings::get("enrichment.reader_{$name}_usd_per_million");
    }
}
