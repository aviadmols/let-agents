<?php

namespace App\Modules\Enrichment\Actions;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Enrichment\Enums\BatchStatus;
use App\Modules\Enrichment\Enums\ItemStatus;
use App\Modules\Enrichment\Enums\TaskType;
use App\Modules\Enrichment\Models\EnrichmentBatch;
use App\Modules\Enrichment\Models\EnrichmentBatchItem;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Enrichment\Prompts\PromptLibrary;
use App\Modules\Enrichment\Tasks\TaskRegistry;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Models\Run;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Builds a batch of agent requests: code reads the products or articles, packs each one small,
 * and stores the requests with what is needed to check the answers. Nothing is sent to a model
 * here; the batch is downloaded and run by whichever model the operator chooses.
 */
final class CreateTaskFile
{
    public const ACTION = 'enrichment.create_task_file';

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
        private readonly TaskRegistry $tasks,
    ) {}

    /**
     * @param  array<string, mixed>  $scope  subject (for reviews), include_done
     * @return array{run: Run, batch: EnrichmentBatch|null}
     */
    public function handle(string $shopId, TaskType $type, ?string $vocabularyId = null, int $reviewTier = 1, array $scope = [], ?int $limit = null): array
    {
        $batch = null;
        $cap = (int) Settings::get('enrichment.max_requests_per_task_file', $shopId);
        $limit = max(1, min($limit ?? $cap, $cap));

        $run = $this->runs->track(
            agent: $type->agent(),
            action: self::ACTION,
            shopId: $shopId,
            input: ['task' => $type->value, 'vocabulary_id' => $vocabularyId, 'review_tier' => $reviewTier, 'scope' => $scope, 'limit' => $limit],
            work: function (RunContext $run) use ($shopId, $type, $vocabularyId, $reviewTier, $scope, $limit, &$batch): void {
                $batch = $this->tenant->run($shopId, fn () => $this->build($run, $shopId, $type, $vocabularyId, $reviewTier, $scope, $limit));
            },
        );

        return ['run' => $run, 'batch' => $batch];
    }

    /** @param array<string, mixed> $scope */
    private function build(RunContext $run, string $shopId, TaskType $type, ?string $vocabularyId, int $reviewTier, array $scope, int $limit): ?EnrichmentBatch
    {
        $vocabulary = $vocabularyId === null ? null : EnrichmentVocabulary::query()->find($vocabularyId);
        $needsVocabulary = $type === TaskType::ProductExtraction || $type === TaskType::ProductHighlights || $type === TaskType::ProductSummary || ($type === TaskType::FactReview && ($scope['subject'] ?? 'product') === 'product');

        if ($needsVocabulary && $vocabulary === null) {
            $run->fail('enrichment::runs.no_vocabulary');

            return null;
        }

        // Articles are read against the jobs of every active vocabulary, so "good for" can link them to products.
        $uses = $type === TaskType::ContentMapping
            ? PromptLibrary::uses(EnrichmentVocabulary::query()->where('active', true)->orderBy('key')->get()->map(fn (EnrichmentVocabulary $v) => $v->definition()))
            : [];
        $scope += $uses === [] ? [] : ['uses' => array_column($uses, 'key')];

        $system = PromptLibrary::render($type, $needsVocabulary ? $vocabulary?->definition() : null, $uses);

        return DB::transaction(function () use ($run, $shopId, $type, $vocabulary, $needsVocabulary, $reviewTier, $scope, $limit, $system): ?EnrichmentBatch {
            $batch = EnrichmentBatch::query()->create([
                'shop_id' => $shopId,
                'task' => $type,
                'review_tier' => $type === TaskType::FactReview ? max(1, min(2, $reviewTier)) : 0,
                'prompt_key' => $type->value,
                'prompt_version' => PromptLibrary::version($type),
                'prompt_hash' => PromptLibrary::hash($system),
                'system_prompt' => $system,
                'vocabulary_id' => $needsVocabulary ? $vocabulary?->id : null,
                'scope' => $scope,
                'runner' => 'external',
                'status' => BatchStatus::AwaitingResults,
                'export_run_id' => $run->runId,
                'created_by' => Auth::id(),
            ]);

            $count = 0;
            $textChars = 0;

            foreach ($this->tasks->for($type)->requests($batch, $limit) as $request) {
                EnrichmentBatchItem::query()->create([
                    'batch_id' => $batch->id,
                    'shop_id' => $shopId,
                    'custom_id' => $request->customId,
                    'subject_type' => $request->subjectType,
                    'subject_id' => $request->subjectId,
                    'input_hash' => $request->inputHash,
                    'request' => $request->request,
                    'context' => $request->context,
                    'status' => ItemStatus::Pending,
                ]);

                $count++;
                $textChars += mb_strlen((string) json_encode($request->request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }

            if ($count === 0) {
                $batch->delete();
                $run->output(['requests' => 0])->summary('enrichment::runs.nothing_to_do', ['task' => $type->label()]);

                return null;
            }

            $batch->forceFill(['request_count' => $count])->save();

            $run->output([
                'batch_id' => $batch->id,
                'requests' => $count,
                'request_chars' => $textChars,
                'system_prompt_chars' => mb_strlen($system),
                'prompt_version' => $batch->prompt_version,
            ])->summary('enrichment::runs.task_file_created', [
                'task' => $type->label(),
                'count' => number_format($count),
                'chars' => number_format($textChars),
            ]);

            return $batch;
        });
    }
}
