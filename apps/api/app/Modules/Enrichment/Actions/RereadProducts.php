<?php

namespace App\Modules\Enrichment\Actions;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\AgentModel;
use App\Modules\Enrichment\Enums\TaskType;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Models\Run;

/**
 * A few products read again by the model, now: the ones the gaps screen found missing a detail
 * most products of their kind have. The same prompt, the same request and the same checks in code
 * as the nightly reading; only which products, and that a product read before is read again.
 */
final class RereadProducts
{
    public const AGENT = 'enrichment.nightly_model';

    public const ACTION = 'enrichment.reread_products';

    /** At most this many products at a press. */
    public const MAX = 50;

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
        private readonly CreateTaskFile $batches,
        private readonly AnswerBatchWithModel $answers,
    ) {}

    /** @param  list<string>  $productIds */
    public function handle(string $shopId, string $vocabularyId, array $productIds): Run
    {
        $productIds = array_slice(array_values(array_unique($productIds)), 0, self::MAX);

        return $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            input: ['products' => count($productIds)],
            work: function (RunContext $run) use ($shopId, $vocabularyId, $productIds): void {
                $batch = $productIds === [] ? null : ($this->batches->handle($shopId, TaskType::ProductExtraction, $vocabularyId, scope: ['products' => $productIds, 'include_done' => true], limit: count($productIds))['batch'] ?? null);

                if ($batch === null) {
                    $run->output(['asked' => 0])->summary('enrichment::runs.nightly_model_nothing');

                    return;
                }

                $model = (string) Settings::get('enrichment.reader_model');
                $provider = AgentModel::provider('enrichment.reader_provider');
                $counts = $this->tenant->run($shopId, fn (): array => $this->answers->handle($run, $batch, $model, count($productIds), $provider));

                $run->output($counts + ['batch' => $batch->id])->summary('enrichment::runs.nightly_model', [
                    'asked' => number_format($counts['asked']),
                    'applied' => number_format($counts['applied']),
                    'rejected' => number_format($counts['rejected']),
                ]);
            },
        );
    }
}
