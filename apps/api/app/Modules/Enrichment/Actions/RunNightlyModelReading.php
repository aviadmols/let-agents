<?php

namespace App\Modules\Enrichment\Actions;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\AgentModel;
use App\Modules\Ai\Contracts\BatchChatModel;
use App\Modules\Enrichment\Enums\BatchStatus;
use App\Modules\Enrichment\Enums\ItemStatus;
use App\Modules\Enrichment\Enums\TaskType;
use App\Modules\Enrichment\Models\EnrichmentBatch;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Models\Run;

/**
 * The products a model has not read yet, read tonight — a fixed number of them.
 *
 * Model reading was the one step that needed a person to carry a file to an agent and carry the
 * answers back, which meant in practice it happened once and then never again. A shop that gains
 * products gains products nobody has read.
 *
 * So it asks directly, for as many products as the shop allows a night, and stops on the month's
 * cap rather than on a person's attention. Everything else is unchanged: the same prompt, the
 * same request, and every answer checked in code before a single fact is written.
 *
 * A night reads a bounded number on purpose. It keeps the spend predictable, it spreads a large
 * catalogue over a week or two instead of one expensive evening, and it means a prompt that turns
 * out to be wrong costs one night rather than the whole catalogue.
 *
 * Where the provider has a batch API (enrichment.batch_reading), the questions are handed over in
 * one go at half the price and the answers are collected during the day; the night then only
 * submits. The first scan asks for every unread product at once ($allProducts): batch after batch
 * until nothing is left, which is the one time a whole catalogue is read in an evening.
 */
final class RunNightlyModelReading
{
    public const AGENT = 'enrichment.nightly_model';

    public const ACTION = 'enrichment.nightly_model';

    /** Batches handed over in one "all products" reading, at most: a guard, not a cap. */
    private const MAX_ROUNDS = 100;

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
        private readonly CreateTaskFile $batches,
        private readonly AnswerBatchWithModel $answers,
        private readonly SubmitBatchToProvider $submit,
        private readonly BatchChatModel $provider,
    ) {}

    public function handle(string $shopId, bool $allProducts = false): Run
    {
        return $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            input: ['all_products' => $allProducts],
            work: function (RunContext $run) use ($shopId, $allProducts): void {
                $limit = (int) Settings::get('enrichment.nightly_model_requests', $shopId);

                if ($limit === 0 && ! $allProducts) {
                    $run->output(['asked' => 0])->summary('enrichment::runs.nightly_model_off');

                    return;
                }

                $model = (string) Settings::get('enrichment.reader_model');
                $provider = AgentModel::provider('enrichment.reader_provider');
                $viaBatch = Features::enabled('enrichment.batch_reading', $shopId) && $this->provider->supports($provider);
                // Through a batch API (half the price, nothing waits for it) a night catches up: round after
                // round of what is unread or changed, up to nightly_batch_requests, so a catalogue is covered
                // in one night instead of a month of 40; after that only new and changed products are left.
                $catchUp = $viaBatch && ! $allProducts ? (int) Settings::get('enrichment.nightly_batch_requests', $shopId) : 0;
                // The whole catalogue (or the catch-up) goes over in batches as large as a task file may be.
                $perFile = (int) Settings::get('enrichment.max_requests_per_task_file', $shopId);
                $limit = $allProducts ? $perFile : ($catchUp > 0 ? min($perFile, $catchUp) : $limit);

                // A shop's catalogue is divided between several vocabularies, and a product can
                // only be read against the one its branch belongs to. Taking the first would mean
                // reading one branch until it was finished and never touching the others, so each
                // is tried in turn until one still has something unread.
                $vocabularies = $this->tenant->run($shopId, fn () => EnrichmentVocabulary::query()->where('active', true)->orderBy('key')->get());

                // A batch somebody exported and never brought answers back for leaves its
                // products marked as already asked, so nothing would ever pick them up again.
                // Those are answered first: they are the oldest questions waiting. One already
                // in a provider's hands is the collector's, not tonight's.
                $batch = $this->tenant->run($shopId, fn () => EnrichmentBatch::query()
                    ->where('status', BatchStatus::AwaitingResults)
                    ->whereNull('provider_batch_id')
                    ->where('runner', '!=', CompareReaders::RUNNER)
                    ->whereHas('items', fn ($q) => $q->where('status', ItemStatus::Pending))
                    ->oldest('created_at')
                    ->first());

                $totals = ['asked' => 0, 'applied' => 0, 'rejected' => 0, 'stale' => 0, 'submitted' => 0, 'batches' => 0, 'stopped' => null, 'via' => $viaBatch ? 'batch' : 'direct', 'model' => $model];

                // The details first; then what a shopper should know from each description (up to
                // four points, each resting on a quote), which the other family reviews.
                $tasks = array_values(array_filter([
                    TaskType::ProductExtraction,
                    Features::enabled('enrichment.auto_highlights', $shopId) ? TaskType::ProductHighlights : null,
                    // A short description of what each product is and is for, for search (FactNotes), after its details.
                    Features::enabled('enrichment.search_summary', $shopId) ? TaskType::ProductSummary : null,
                ]));

                for ($round = 0; $round < self::MAX_ROUNDS; $round++) {
                    if ($batch === null) {
                        foreach ($tasks as $task) {
                            foreach ($vocabularies as $vocabulary) {
                                $batch = $this->batches->handle($shopId, $task, $vocabulary->id, limit: $limit)['batch'] ?? null;

                                if ($batch !== null) {
                                    break 2;
                                }
                            }
                        }
                    }

                    // Nothing to read is the ordinary case for a shop that has caught up.
                    if ($batch === null) {
                        break;
                    }

                    $totals['batches']++;
                    $totals['batch'] ??= $batch->id;

                    if ($viaBatch) {
                        $sent = $this->tenant->run($shopId, fn (): array => $this->submit->handle($run, $batch, $model, $provider));
                        $totals['submitted'] += $sent['submitted'];
                        $totals['stale'] += $sent['stale'];
                        $stopped = $sent['stopped'];
                    } else {
                        $counts = $this->tenant->run($shopId, fn (): array => $this->answers->handle($run, $batch, $model, $limit, $provider));
                        foreach (['asked', 'applied', 'rejected', 'stale'] as $key) {
                            $totals[$key] += $counts[$key];
                        }
                        $stopped = $counts['stopped'];
                    }

                    if ($stopped !== null) {
                        $totals['stopped'] = $stopped;

                        break;
                    }

                    if (! $allProducts && ($catchUp === 0 || $totals['submitted'] >= $catchUp)) {
                        break;
                    }

                    if ($catchUp > 0) {
                        $limit = min($perFile, $catchUp - $totals['submitted']);
                    }

                    // The next round builds the next batch: what was just handed over counts as asked.
                    $batch = null;
                }

                $run->output($totals + ['vocabularies' => $vocabularies->count()]);

                if ($totals['batches'] === 0) {
                    $run->summary('enrichment::runs.nightly_model_nothing');
                } elseif ($viaBatch) {
                    $run->summary('enrichment::runs.nightly_model_submitted', ['submitted' => number_format($totals['submitted']), 'batches' => (string) $totals['batches']]);
                } else {
                    $run->summary('enrichment::runs.nightly_model', ['asked' => number_format($totals['asked']), 'applied' => number_format($totals['applied']), 'rejected' => number_format($totals['rejected'])]);
                }
            },
        );
    }
}
