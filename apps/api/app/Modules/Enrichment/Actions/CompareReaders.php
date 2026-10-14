<?php

namespace App\Modules\Enrichment\Actions;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\AgentModel;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Contracts\SpendCapReached;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Enums\ItemStatus;
use App\Modules\Enrichment\Enums\TaskType;
use App\Modules\Enrichment\Models\EnrichmentBatch;
use App\Modules\Enrichment\Models\EnrichmentBatchItem;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Enrichment\Support\VocabularyBranch;
use App\Modules\Enrichment\Tasks\TaskRegistry;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Models\Run;
use Illuminate\Support\Facades\DB;

/**
 * Two reading models on the same products, side by side, before choosing one: the night's reader
 * (enrichment.reader_model) and another (enrichment.compare_model). Each reads the same requests
 * with the same prompt; each answer goes through the same checks in code, inside a transaction
 * that is rolled back, so nothing either model said is kept. What is kept is the comparison: how
 * many details each found, how many passed the checks or wait for review, where they disagree,
 * and what each cost.
 *
 * The products are a fixed sample (by a stable hash of their id), shared between the shop's
 * vocabularies, so running it again compares the same products.
 */
final class CompareReaders
{
    public const AGENT = 'enrichment.nightly_model';

    public const ACTION = 'enrichment.compare_readers';

    /** A comparison's own questions: the night never answers them. */
    public const RUNNER = 'comparison';

    /** Disagreements shown, at most. */
    private const EXAMPLES = 25;

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
        private readonly CreateTaskFile $tasks,
        private readonly TaskRegistry $registry,
        private readonly ChatModel $chat,
        private readonly SpendGuard $spend,
    ) {}

    public function handle(string $shopId): Run
    {
        return $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            work: function (RunContext $run) use ($shopId): void {
                $readers = [
                    'a' => self::reader('reader'),
                    'b' => self::reader('compare'),
                ];

                if ($readers['a']['model'] === $readers['b']['model'] && $readers['a']['provider'] === $readers['b']['provider']) {
                    $run->fail('enrichment::runs.compare_same');

                    return;
                }

                $count = (int) Settings::get('enrichment.compare_products', $shopId);
                $batches = $this->tenant->run($shopId, fn (): array => $this->sample($shopId, $count));

                if ($batches === []) {
                    $run->output(['products' => 0])->summary('enrichment::runs.compare_nothing');

                    return;
                }

                try {
                    $report = $this->tenant->run($shopId, fn (): array => $this->compare($run, $batches, $readers));
                } finally {
                    // The questions were only for this comparison: nothing of them is left behind.
                    $this->tenant->run($shopId, fn () => EnrichmentBatch::query()->whereIn('id', array_map(fn (EnrichmentBatch $b): string => $b->id, $batches))->delete());
                }

                $run->output($report)->summary('enrichment::runs.compared', [
                    'products' => (string) $report['products'],
                    'a' => $readers['a']['model'],
                    'a_facts' => (string) $report['readers']['a']['facts'],
                    'b' => $readers['b']['model'],
                    'b_facts' => (string) $report['readers']['b']['facts'],
                    'same' => (string) $report['agreement']['same'],
                    'different' => (string) $report['agreement']['different'],
                ]);
            },
        );
    }

    /** @return array{provider: AiProviderName, model: string, in: float, out: float} */
    private static function reader(string $role): array
    {
        return [
            'provider' => AgentModel::provider("enrichment.{$role}_provider"),
            'model' => (string) Settings::get("enrichment.{$role}_model"),
            'in' => (float) Settings::get("enrichment.{$role}_input_usd_per_million"),
            'out' => (float) Settings::get("enrichment.{$role}_output_usd_per_million"),
        ];
    }

    /**
     * The same products every time, shared between the vocabularies, as batches of questions.
     *
     * @return list<EnrichmentBatch>
     */
    private function sample(string $shopId, int $count): array
    {
        $vocabularies = EnrichmentVocabulary::query()->where('active', true)->orderBy('key')->get();

        if ($vocabularies->isEmpty()) {
            return [];
        }

        $share = (int) ceil($count / $vocabularies->count());
        $batches = [];
        $taken = 0;

        foreach ($vocabularies as $vocabulary) {
            $ids = VocabularyBranch::products($vocabulary->definition())->whereNull('removed_at')->pluck('id')
                ->map(fn ($id): string => (string) $id)
                ->sortBy(fn (string $id): string => hash('crc32b', $id))
                ->take(min($share, $count - $taken))
                ->values()->all();

            if ($ids === []) {
                continue;
            }

            $batch = $this->tasks->handle($shopId, TaskType::ProductExtraction, $vocabulary->id, scope: ['products' => $ids, 'include_done' => true, 'comparison' => true], limit: count($ids))['batch'] ?? null;

            if ($batch !== null) {
                $batch->forceFill(['runner' => self::RUNNER])->save();
                $batches[] = $batch;
                $taken += $batch->request_count;
            }

            if ($taken >= $count) {
                break;
            }
        }

        return $batches;
    }

    /**
     * @param  list<EnrichmentBatch>  $batches
     * @param  array<string, array{provider: AiProviderName, model: string, in: float, out: float}>  $readers
     * @return array<string, mixed>
     */
    private function compare(RunContext $run, array $batches, array $readers): array
    {
        $items = EnrichmentBatchItem::query()->whereIn('batch_id', array_map(fn (EnrichmentBatch $b): string => $b->id, $batches))
            ->where('status', ItemStatus::Pending)->orderBy('custom_id')->get();
        $titles = CatalogProduct::query()->whereIn('id', $items->pluck('subject_id'))->pluck('title', 'id');
        $report = ['products' => $items->count(), 'readers' => [], 'agreement' => [], 'differences' => []];
        $found = [];

        foreach ($readers as $side => $reader) {
            [$replies, $usage] = $this->ask($run, $batches, $items, $reader);
            [$facts, $outcomes] = $this->dryRun($batches, $items, $replies, $reader['model']);
            $found[$side] = $facts;
            $statuses = array_count_values(array_column($facts, 'status'));
            $keys = array_count_values(array_column($facts, 'key'));
            arsort($keys);

            $report['readers'][$side] = [
                'provider' => $reader['provider']->value,
                'model' => $reader['model'],
                'answered' => count($replies),
                'failed_calls' => $usage['failed'],
                'stopped' => $usage['stopped'],
                'applied' => $outcomes[ItemStatus::Applied->value] ?? 0,
                'rejected' => $outcomes[ItemStatus::Rejected->value] ?? 0,
                'facts' => count($facts),
                'approved' => $statuses[FactStatus::Approved->value] ?? 0,
                'review' => count($facts) - ($statuses[FactStatus::Approved->value] ?? 0) - ($statuses[FactStatus::Rejected->value] ?? 0),
                'products_with_facts' => count(array_unique(array_column($facts, 'product'))),
                'by_key' => array_slice($keys, 0, 15, true),
                'input_tokens' => $usage['input'],
                'output_tokens' => $usage['output'],
                'cost_usd' => round($usage['cost'], 6),
                'cost_per_product_usd' => count($replies) === 0 ? 0 : round($usage['cost'] / count($replies), 7),
            ];
        }

        $a = $found['a'];
        $b = $found['b'];
        $same = 0;
        $different = 0;

        foreach (array_intersect_key($a, $b) as $at => $fact) {
            if ($fact['value'] === $b[$at]['value']) {
                $same++;

                continue;
            }

            $different++;

            if (count($report['differences']) < self::EXAMPLES) {
                $report['differences'][] = ['product' => mb_substr((string) ($titles[$fact['product']] ?? ''), 0, 80), 'key' => $fact['key'], 'a' => $fact['value'], 'b' => $b[$at]['value']];
            }
        }

        $report['agreement'] = ['same' => $same, 'different' => $different, 'only_a' => count(array_diff_key($a, $b)), 'only_b' => count(array_diff_key($b, $a))];
        $catalog = CatalogProduct::query()->whereNull('removed_at')->count();
        $report['catalog_products'] = $catalog;
        foreach ($report['readers'] as $side => $row) {
            $report['readers'][$side]['catalog_estimate_usd'] = round($row['cost_per_product_usd'] * $catalog, 2);
        }

        return $report;
    }

    /**
     * Every question of the sample asked of one reader, one at a time.
     *
     * @param  list<EnrichmentBatch>  $batches
     * @param  iterable<EnrichmentBatchItem>  $items
     * @param  array{provider: AiProviderName, model: string, in: float, out: float}  $reader
     * @return array{0: array<string, ModelReply>, 1: array{input: int, output: int, cost: float, failed: int, stopped: ?string}}
     */
    private function ask(RunContext $run, array $batches, iterable $items, array $reader): array
    {
        $systems = collect($batches)->mapWithKeys(fn (EnrichmentBatch $b): array => [$b->id => (string) $b->system_prompt]);
        $maxOutput = (int) Settings::get('enrichment.model_answer_tokens');
        $replies = [];
        $usage = ['input' => 0, 'output' => 0, 'cost' => 0.0, 'failed' => 0, 'stopped' => null];

        foreach ($items as $item) {
            $system = (string) $systems[$item->batch_id];
            $user = (string) json_encode($item->request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            try {
                $this->spend->assertCanSpend(((mb_strlen($system) + mb_strlen($user)) / 3 * $reader['in'] + $maxOutput * $reader['out']) / 1_000_000);
                $reply = $this->chat->json($reader['provider'], $reader['model'], $system, $user, $maxOutput);
            } catch (SpendCapReached) {
                $usage['stopped'] = 'spend_cap';

                break;
            } catch (ModelCallFailed) {
                $usage['failed']++;

                continue;
            }

            $cost = $reply->costUsd($reader['in'], $reader['out']);
            $run->usage($reader['provider']->value, $reader['model'], $reply->inputTokens, $reply->outputTokens, 0, $cost);
            $usage['input'] += $reply->inputTokens;
            $usage['output'] += $reply->outputTokens;
            $usage['cost'] += $cost;
            $replies[$item->id] = $reply;
        }

        return [$replies, $usage];
    }

    /**
     * The answers through the task's own checks, as if they were kept, and then undone: the facts
     * each answer would have written, by product, kind and key.
     *
     * @param  list<EnrichmentBatch>  $batches
     * @param  iterable<EnrichmentBatchItem>  $items
     * @param  array<string, ModelReply>  $replies
     * @return array{0: array<string, array{product: string, key: string, value: string, status: string}>, 1: array<string, int>}
     */
    private function dryRun(array $batches, iterable $items, array $replies, string $model): array
    {
        $byId = collect($batches)->keyBy('id');
        $task = $this->registry->for(TaskType::ProductExtraction);
        $facts = [];
        $outcomes = [];

        DB::beginTransaction();

        try {
            foreach ($items as $item) {
                $reply = $replies[$item->id] ?? null;

                if ($reply === null) {
                    continue;
                }

                $outcome = $task->apply($byId[$item->batch_id], $item, $reply->data, $model);
                $outcomes[$outcome->status->value] = ($outcomes[$outcome->status->value] ?? 0) + 1;
            }

            EnrichmentFact::query()->whereIn('batch_id', $byId->keys())->where('status', '!=', FactStatus::Superseded)
                ->orderBy('key')->orderBy('value_text')->get()
                ->each(function (EnrichmentFact $fact) use (&$facts): void {
                    $value = $fact->value_number !== null ? rtrim(rtrim((string) $fact->value_number, '0'), '.').($fact->unit ? ' '.$fact->unit : '') : (string) $fact->value_text;
                    $at = $fact->product_id.'|'.$fact->kind->value.'|'.$fact->key;
                    // A key with several values (two stones) is one answer: its values together, sorted.
                    $facts[$at] = isset($facts[$at])
                        ? ['value' => implode(', ', array_unique([...explode(', ', $facts[$at]['value']), $value]))] + $facts[$at]
                        : ['product' => (string) $fact->product_id, 'key' => $fact->key, 'value' => $value, 'status' => $fact->status->value];
                });
        } finally {
            DB::rollBack();
        }

        return [$facts, $outcomes];
    }
}
