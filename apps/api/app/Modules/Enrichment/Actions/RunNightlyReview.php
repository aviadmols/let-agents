<?php

namespace App\Modules\Enrichment\Actions;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\AgentModel;
use App\Modules\Ai\Contracts\BatchChatModel;
use App\Modules\Enrichment\Enums\BatchStatus;
use App\Modules\Enrichment\Enums\TaskType;
use App\Modules\Enrichment\Models\EnrichmentBatch;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Models\Run;

/**
 * What the night's reading could not settle on its own, checked by a model of the other family.
 *
 * The reader (OpenAI by default) leaves a detail waiting for review when it is not sure enough: a
 * type its hints did not agree on, a value only the model saw. Until now those waited for a person.
 * Now the reviewer (Claude by default) reads each product's text with the waiting details and
 * says of each: right, wrong, or unsure. Right is approved, wrong rejected, unsure goes on to a
 * person, as the review task always did. The two must be of different families; equal families
 * stop the step before any call.
 *
 * Through the provider's batch API when the shop reads in batches, one review batch at a time:
 * while one is with the provider, no other is made.
 */
final class RunNightlyReview
{
    public const AGENT = 'enrichment.nightly_model';

    public const ACTION = 'enrichment.nightly_review';

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
        private readonly CreateTaskFile $batches,
        private readonly AnswerBatchWithModel $answers,
        private readonly SubmitBatchToProvider $submit,
        private readonly BatchChatModel $provider,
    ) {}

    public function handle(string $shopId): Run
    {
        return $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            work: function (RunContext $run) use ($shopId): void {
                $limit = (int) Settings::get('enrichment.nightly_review_requests', $shopId);

                if ($limit === 0 || ! Features::enabled('enrichment.auto_review', $shopId)) {
                    $run->output(['asked' => 0])->summary('enrichment::runs.review_off');

                    return;
                }

                $reviewer = AgentModel::from('enrichment.reviewer_provider', 'enrichment.reviewer_model');
                $reader = AgentModel::provider('enrichment.reader_provider');

                // What one family wrote, the other checks.
                if ($reviewer->provider->family() === $reader->family()) {
                    $run->fail('enrichment::runs.review_same_family');

                    return;
                }

                $viaBatch = Features::enabled('enrichment.batch_reading', $shopId) && $this->provider->supports($reviewer->provider);
                $waiting = fn () => EnrichmentBatch::query()->where('task', TaskType::FactReview)->where('status', BatchStatus::AwaitingResults)
                    ->where('runner', '!=', CompareReaders::RUNNER);

                // One review at a time: one with the provider is the collector's.
                if ($this->tenant->run($shopId, fn (): bool => $waiting()->whereNotNull('provider_batch_id')->exists())) {
                    $run->output(['asked' => 0, 'waiting' => true])->summary('enrichment::runs.review_waiting');

                    return;
                }

                $batch = $this->tenant->run($shopId, fn () => $waiting()->whereNull('provider_batch_id')->oldest('created_at')->first());

                if ($batch === null) {
                    foreach ($this->tenant->run($shopId, fn () => EnrichmentVocabulary::query()->where('active', true)->orderBy('key')->get()) as $vocabulary) {
                        $batch = $this->batches->handle($shopId, TaskType::FactReview, $vocabulary->id, 1, ['subject' => 'product'], $limit)['batch'] ?? null;

                        if ($batch !== null) {
                            break;
                        }
                    }
                }

                if ($batch === null) {
                    $run->output(['asked' => 0])->summary('enrichment::runs.review_nothing');

                    return;
                }

                if ($viaBatch) {
                    $sent = $this->tenant->run($shopId, fn (): array => $this->submit->handle($run, $batch, $reviewer->model, $reviewer->provider));
                    $run->output($sent + ['batch' => $batch->id, 'via' => 'batch', 'model' => $reviewer->model])
                        ->summary('enrichment::runs.review_submitted', ['submitted' => number_format($sent['submitted'])]);

                    return;
                }

                $counts = $this->tenant->run($shopId, fn (): array => $this->answers->handle($run, $batch, $reviewer->model, $limit, $reviewer->provider));
                $run->output($counts + ['batch' => $batch->id, 'via' => 'direct', 'model' => $reviewer->model])
                    ->summary('enrichment::runs.reviewed', ['asked' => number_format($counts['asked']), 'applied' => number_format($counts['applied'])]);
            },
        );
    }
}
