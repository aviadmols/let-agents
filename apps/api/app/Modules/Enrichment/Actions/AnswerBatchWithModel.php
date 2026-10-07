<?php

namespace App\Modules\Enrichment\Actions;

use App\Core\Facades\Settings;
use App\Modules\Ai\Contracts\ChatModel;
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
 * Answers a batch's questions with a model, instead of waiting for someone to carry a file.
 *
 * A batch was always a set of questions with a prompt, and a file was only ever how the questions
 * reached somebody who could answer them. This is the other way: the same questions, the same
 * prompt, the same checking of every answer in code afterwards, asked directly.
 *
 * Nothing about what is accepted changes. A task decides whether an answer still matches its
 * subject and turns it into checked facts exactly as it does for an answer that arrived in a
 * file, so a fact written here is a fact that passed the same gate.
 *
 * It stops the moment the month's cap is in sight, and says how far it got.
 */
final class AnswerBatchWithModel
{
    public function __construct(
        private readonly TaskRegistry $tasks,
        private readonly ChatModel $models,
        private readonly SpendGuard $spend,
    ) {}

    /**
     * @return array{asked: int, applied: int, rejected: int, stale: int, stopped: ?string}
     */
    public function handle(RunContext $run, EnrichmentBatch $batch, string $model, int $limit, AiProviderName $provider = AiProviderName::OpenAi): array
    {
        $task = $this->tasks->for($batch->task);
        $maxOutput = (int) Settings::get('enrichment.model_answer_tokens', $batch->shop_id);
        $counts = ['asked' => 0, 'applied' => 0, 'rejected' => 0, 'stale' => 0, 'stopped' => null];

        $items = EnrichmentBatchItem::query()
            ->where('batch_id', $batch->id)
            ->where('status', ItemStatus::Pending)
            ->orderBy('custom_id')
            ->limit($limit)
            ->get();

        foreach ($items as $item) {
            // A subject that changed while the batch waited is not asked about at all.
            if ($task->isStale($batch, $item)) {
                $item->forceFill(['status' => ItemStatus::Stale])->save();
                $counts['stale']++;

                continue;
            }

            $user = (string) json_encode($item->request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            try {
                $this->spend->assertCanSpend($this->estimate($batch->system_prompt, $user, $maxOutput));

                $reply = $this->models->json(
                    $provider,
                    $model,
                    (string) $batch->system_prompt,
                    $user,
                    $maxOutput,
                );
            } catch (SpendCapReached) {
                // The rest stay pending: tomorrow night picks them up where this one stopped.
                $counts['stopped'] = 'spend_cap';

                break;
            } catch (ModelCallFailed $e) {
                $counts['stopped'] = $e->reason;

                break;
            }

            $run->usage($provider->value, $model, $reply->inputTokens, $reply->outputTokens, 0, $reply->costUsd(
                $this->price('input'),
                $this->price('output'),
            ));
            $counts['asked']++;

            $outcome = $task->apply($batch, $item, $reply->data, $model);

            $item->forceFill([
                'status' => $outcome->status,
                'result' => $reply->data,
                'problems' => $outcome->problems,
            ])->save();

            $counts[$outcome->status === ItemStatus::Applied ? 'applied' : 'rejected']++;
        }

        $pending = EnrichmentBatchItem::query()->where('batch_id', $batch->id)->where('status', ItemStatus::Pending)->count();

        $batch->forceFill([
            'status' => $pending === 0 ? BatchStatus::Completed : BatchStatus::AwaitingResults,
            'runner' => 'model',
            'model' => $model,
            'result_count' => $counts['applied'] + $counts['rejected'],
            'accepted_count' => $counts['applied'],
            'rejected_count' => $counts['rejected'],
            'completed_at' => $pending === 0 ? now() : null,
        ])->save();

        return $counts;
    }

    /** Roughly what one question will cost, before asking whether there is room for it. */
    private function estimate(string $system, string $user, int $maxOutput): float
    {
        $input = (mb_strlen($system) + mb_strlen($user)) / 3;

        return $input * $this->price('input') / 1_000_000
            + $maxOutput * $this->price('output') / 1_000_000;
    }

    private function price(string $name): float
    {
        return (float) Settings::get("enrichment.reader_{$name}_usd_per_million");
    }
}
