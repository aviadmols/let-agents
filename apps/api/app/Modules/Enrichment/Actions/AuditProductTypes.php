<?php

namespace App\Modules\Enrichment\Actions;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\AgentModel;
use App\Modules\Ai\Contracts\BatchChatModel;
use App\Modules\Ai\Contracts\BatchRequest;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\SpendCapReached;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Ai\Contracts\StructuredChatModel;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Enrichment\Enums\BatchStatus;
use App\Modules\Enrichment\Enums\ItemStatus;
use App\Modules\Enrichment\Enums\TaskType;
use App\Modules\Enrichment\Events\FactsDecided;
use App\Modules\Enrichment\Models\EnrichmentBatch;
use App\Modules\Enrichment\Models\EnrichmentBatchItem;
use App\Modules\Enrichment\Models\EnrichmentExpertBank;
use App\Modules\Enrichment\Models\EnrichmentTypeAudit;
use App\Modules\Enrichment\Prompts\PromptLibrary;
use App\Modules\Enrichment\Support\ReplySchema;
use App\Modules\Enrichment\Tasks\TypeAuditTask;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Models\Run;

/**
 * The type audit: the few products whose type code read from the store's filing alone and cannot vouch for (TypeDoubt),
 * put to a model of another family than the one that wrote the bank's words, which says which type of the bank each is,
 * or none. Code applies the verdict (TypeAuditTask::decide) and the search follows the audited type.
 *
 * 1. Code picks the doubtful products: a type from a category or a tag only, the title silent on it, and the picture
 *    silent too or the filing itself loose. Typically a few percent of a store. Counted and said.
 * 2. Never asked twice: a question with the same fingerprint (title, caption, the store's names, the bank's types, the
 *    type code gave, the prompt's version) answered before is applied again from the stored verdict, without a call.
 * 3. The rest go to the auditor, through the provider's batch API where the shop reads in batches (the answers are
 *    collected later, CollectProviderBatches, and applied through the same task), else one by one now. SpendGuard
 *    first, structured output always, the reply held to its schema in code, tokens and cost on the run.
 *
 * The bank's writer and the auditor are checked to be of different families before any product is read; equal families
 * fail the run. Where no bank of a model is active (a ready vocabulary), there is no model writer to cross.
 */
final class AuditProductTypes
{
    public const AGENT = 'enrichment.type_auditor';

    public const ACTION = 'enrichment.audit_types';

    public const FEATURE = 'enrichment.type_audit';

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
        private readonly ChatModel $models,
        private readonly BatchChatModel $batches,
        private readonly SpendGuard $spend,
        private readonly TypeAuditTask $task,
    ) {}

    /** @param  bool  $force  ask again though the same question was answered */
    public function handle(string $shopId, bool $force = false): Run
    {
        return $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            input: ['force' => $force],
            work: fn (RunContext $run) => $this->tenant->run($shopId, fn () => $this->audit($run, $shopId, $force)),
        );
    }

    private function audit(RunContext $run, string $shopId, bool $force): void
    {
        if (! Features::enabled(self::FEATURE, $shopId)) {
            $run->fail('enrichment::runs.type_audit_off');

            return;
        }

        $auditor = AgentModel::from('enrichment.type_auditor_provider', 'enrichment.type_auditor_model');
        // What one family wrote (the bank's words, read by code), the other audits. Decided before a product is read.
        $writer = self::bankWriter();
        if ($writer !== null && $writer->family() === $auditor->provider->family()) {
            $run->fail('enrichment::runs.type_audit_same_family', ['writer' => $writer->value, 'auditor' => $auditor->provider->value], 'same family: '.$writer->value.' / '.$auditor->provider->value);

            return;
        }

        $threshold = (int) Settings::get('enrichment.type_audit_min_confidence', $shopId);
        $cap = (int) Settings::get('enrichment.type_audit_max_products', $shopId);
        $steps = [];
        $say = function (string $key, array $params = []) use (&$steps, $run): void {
            // One line a step, in Hebrew, on the run as it goes.
            $steps[] = (string) trans('enrichment::runs.'.$key, $params, 'he');
            $run->output(['steps' => $steps]);
        };

        // 1. Code picks the doubtful products.
        $doubts = TypeAuditTask::doubts($typed);
        $reasons = array_count_values(array_merge([], ...array_map(fn (array $d): array => $d['doubt']['reasons'], $doubts)));
        ksort($reasons, SORT_STRING);
        $counts = ['typed' => $typed, 'doubtful' => count($doubts), 'reasons' => $reasons, 'reused' => 0, 'waiting' => 0, 'asked' => 0, 'applied' => 0, 'removed' => 0, 'kept' => 0, 'held' => 0, 'rejected' => 0, 'stale' => 0];
        $say('type_audit_step_picked', [
            'typed' => number_format($typed), 'doubtful' => number_format(count($doubts)),
            'share' => $typed > 0 ? round(count($doubts) / $typed * 100, 1) : 0,
            'reasons' => implode(', ', array_map(fn (string $reason, int $n): string => trans('enrichment::runs.type_audit_reasons.'.$reason, [], 'he').' '.$n, array_keys($reasons), $reasons)) ?: '-',
        ]);

        // 2. A stored verdict is applied again; a question with the provider waits; the rest are asked.
        $ask = [];
        foreach ($doubts as $doubt) {
            $stored = EnrichmentTypeAudit::query()->where('product_id', $doubt['product']->id)->where('fingerprint', $doubt['fingerprint'])->first();
            if ($stored !== null && ! $force && in_array($stored->status, EnrichmentTypeAudit::ANSWERED, true)) {
                $status = TypeAuditTask::decide($stored, (string) $stored->type, (int) $stored->confidence, (string) $stored->why, $stored->model, $threshold);
                $counts['reused']++;
                $counts[$status]++;

                continue;
            }
            if ($stored !== null && $stored->status === EnrichmentTypeAudit::ASKED && $stored->batch?->provider_batch_id !== null) {
                $counts['waiting']++;

                continue;
            }
            if (count($ask) < $cap) {
                $ask[] = $doubt;
            }
        }
        if ($counts['reused'] > 0 || $counts['waiting'] > 0) {
            $say('type_audit_step_reused', ['reused' => $counts['reused'], 'waiting' => $counts['waiting']]);
        }
        if ($counts['applied'] + $counts['removed'] > 0) {
            event(new FactsDecided($shopId));
        }
        if ($ask === []) {
            $run->output($counts)->summary('enrichment::runs.type_audit_nothing', ['typed' => number_format($typed), 'doubtful' => number_format(count($doubts)), 'reused' => $counts['reused']]);

            return;
        }

        // 3. The auditor: one batch of questions, or one by one now.
        $version = PromptLibrary::CURRENT[TypeAuditTask::PROMPT];
        $system = PromptLibrary::render(TaskType::TypeAudit, null);
        $schema = ReplySchema::load(TypeAuditTask::PROMPT, $version);
        $in = (float) Settings::get('enrichment.type_auditor_input_usd_per_million');
        $out = (float) Settings::get('enrichment.type_auditor_output_usd_per_million');
        $viaBatch = Features::enabled('enrichment.batch_reading', $shopId) && $this->batches->supports($auditor->provider);
        $requests = [];
        $estimate = 0.0;
        foreach ($ask as $doubt) {
            $request = TypeAuditTask::request($doubt);
            $user = (string) json_encode($request->request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $requests[] = [$request, $user];
            $estimate += ((mb_strlen($system) + mb_strlen($user)) / 3 * $in + TypeAuditTask::MAX_OUTPUT_TOKENS * $out) / 1_000_000;
        }

        try {
            // the whole batch asked of the cap first, at the batch price; one by one, each question asks before it is sent
            if ($viaBatch) {
                $this->spend->assertCanSpend($estimate * BatchChatModel::DISCOUNT);
            }
        } catch (SpendCapReached) {
            $run->output($counts)->fail('enrichment::runs.type_audit_spend_cap');

            return;
        }

        $batch = EnrichmentBatch::query()->create([
            'shop_id' => $shopId,
            'task' => TaskType::TypeAudit,
            'review_tier' => 0,
            'prompt_key' => TypeAuditTask::PROMPT,
            'prompt_version' => $version,
            'prompt_hash' => PromptLibrary::hash($system),
            'system_prompt' => $system,
            'vocabulary_id' => null,
            'scope' => ['threshold' => $threshold, 'force' => $force],
            'runner' => $viaBatch ? SubmitBatchToProvider::RUNNER : 'model',
            'provider' => $auditor->provider->value,
            'model' => $auditor->model,
            'status' => BatchStatus::AwaitingResults,
            'export_run_id' => $run->runId,
        ]);
        $items = [];
        foreach ($requests as $i => [$request, $user]) {
            $items[] = EnrichmentBatchItem::query()->create([
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
            EnrichmentTypeAudit::query()->updateOrCreate(['product_id' => $request->subjectId, 'fingerprint' => $request->inputHash], [
                'shop_id' => $shopId,
                'reasons' => $ask[$i]['doubt']['reasons'],
                'code_type' => $ask[$i]['doubt']['type'],
                'type' => null, 'confidence' => null, 'why' => null, 'problems' => null, 'answered_at' => null,
                'model' => $auditor->model,
                'status' => EnrichmentTypeAudit::ASKED,
                'batch_id' => $batch->id,
                'run_id' => $run->runId,
            ]);
        }
        $batch->forceFill(['request_count' => count($items)])->save();
        $counts['asked'] = count($items);

        if ($viaBatch) {
            try {
                $id = $this->batches->submit($auditor->provider, $auditor->model, array_map(
                    fn (array $pair): BatchRequest => new BatchRequest($pair[0]->customId, $system, $pair[1], TypeAuditTask::MAX_OUTPUT_TOKENS, null, $schema, TypeAuditTask::EFFORT),
                    $requests,
                ));
            } catch (ModelCallFailed $e) {
                $batch->delete();
                $run->output($counts)->fail('enrichment::runs.type_audit_model_failed', ['reason' => $e->reason], $e->reason);

                return;
            }
            $batch->forceFill(['provider_batch_id' => $id, 'submitted_at' => now()])->save();
            $say('type_audit_step_submitted', ['count' => number_format(count($items)), 'model' => $auditor->model, 'usd' => number_format($estimate * BatchChatModel::DISCOUNT, 2, '.', '')]);
            $run->output($counts + ['batch' => $batch->id, 'provider_batch' => $id, 'via' => 'batch', 'model' => $auditor->model])
                ->summary('enrichment::runs.type_audit_submitted', ['typed' => number_format($typed), 'doubtful' => number_format(count($doubts)), 'count' => number_format(count($items)), 'reused' => $counts['reused']]);

            return;
        }

        $usd = 0.0;
        $stopped = null;
        $called = 0;
        foreach ($items as $i => $item) {
            [, $user] = $requests[$i];
            try {
                $this->spend->assertCanSpend(((mb_strlen($system) + mb_strlen($user)) / 3 * $in + TypeAuditTask::MAX_OUTPUT_TOKENS * $out) / 1_000_000);
                $reply = $this->models instanceof StructuredChatModel
                    ? $this->models->structured($auditor->provider, $auditor->model, $system, $user, $schema, TypeAuditTask::MAX_OUTPUT_TOKENS, TypeAuditTask::EFFORT)
                    : $this->models->json($auditor->provider, $auditor->model, $system, $user, TypeAuditTask::MAX_OUTPUT_TOKENS, TypeAuditTask::EFFORT);
            } catch (SpendCapReached) {
                $stopped = 'spend_cap';
                break;
            } catch (ModelCallFailed $e) {
                $stopped = $e->reason;
                break;
            }
            $cost = $reply->costUsd($in, $out);
            $usd += $cost;
            $called++;
            $run->usage($auditor->provider->value, $auditor->model, $reply->inputTokens, $reply->outputTokens, $reply->cacheReadTokens, $cost);

            if ($this->task->isStale($batch, $item)) {
                $item->forceFill(['status' => ItemStatus::Stale, 'result' => $reply->data])->save();
                $counts['stale']++;

                continue;
            }
            $outcome = $this->task->apply($batch, $item, $reply->data, $auditor->model);
            $item->forceFill(['status' => $outcome->status, 'result' => $reply->data, 'problems' => $outcome->problems])->save();
            if ($outcome->status === ItemStatus::Applied) {
                $counts[(string) $outcome->problems[0]]++;
            } else {
                $counts['rejected']++;
            }
        }
        // the questions a stopped run never asked are not a batch waiting for anyone: the next run asks them again
        $pending = EnrichmentBatchItem::query()->where('batch_id', $batch->id)->where('status', ItemStatus::Pending)->pluck('id');
        if ($pending->isNotEmpty()) {
            EnrichmentTypeAudit::query()->where('batch_id', $batch->id)->where('status', EnrichmentTypeAudit::ASKED)->delete();
            EnrichmentBatchItem::query()->whereIn('id', $pending)->delete();
        }
        $answered = EnrichmentBatchItem::query()->where('batch_id', $batch->id);
        $batch->forceFill([
            'status' => BatchStatus::Completed,
            'result_count' => (clone $answered)->whereIn('status', [ItemStatus::Applied, ItemStatus::Rejected])->count(),
            'accepted_count' => (clone $answered)->where('status', ItemStatus::Applied)->count(),
            'rejected_count' => (clone $answered)->where('status', ItemStatus::Rejected)->count(),
            'completed_at' => now(),
        ])->save();

        $say('type_audit_step_answered', [
            'model' => $auditor->model, 'asked' => $called,
            'applied' => $counts['applied'], 'removed' => $counts['removed'], 'kept' => $counts['kept'], 'held' => $counts['held'], 'rejected' => $counts['rejected'],
            'threshold' => $threshold, 'usd' => number_format($usd, 2, '.', ''),
        ]);
        if ($counts['applied'] + $counts['removed'] > 0) {
            event(new FactsDecided($shopId));
        }

        $params = ['typed' => number_format($typed), 'doubtful' => number_format(count($doubts)), 'applied' => $counts['applied'], 'removed' => $counts['removed'], 'kept' => $counts['kept'], 'held' => $counts['held'], 'rejected' => $counts['rejected'], 'reused' => $counts['reused']];
        $counts['asked'] = $called;
        $run->output($counts + ['batch' => $batch->id, 'via' => 'direct', 'model' => $auditor->model, 'usd' => round($usd, 4), 'stopped' => $stopped]);
        if ($stopped !== null) {
            $say($stopped === 'spend_cap' ? 'type_audit_spend_cap' : 'type_audit_model_failed', ['reason' => $stopped]);
            $run->fail($stopped === 'spend_cap' ? 'enrichment::runs.type_audit_spend_cap' : 'enrichment::runs.type_audit_model_failed', ['reason' => $stopped], $stopped);

            return;
        }
        $run->summary('enrichment::runs.type_audit_done', $params);
    }

    /**
     * The family that wrote the words code reads types by: the activated expert bank's writer, as it was recorded when
     * the bank was written. Null when no bank of a model is active (a ready vocabulary written by people).
     */
    public static function bankWriter(): ?AiProviderName
    {
        $writer = (string) EnrichmentExpertBank::query()->where('status', EnrichmentExpertBank::ACTIVATED)->latest()->value('writer');
        if ($writer === '') {
            return null;
        }

        return AiProviderName::tryFrom(strtolower(trim((string) strtok($writer, '/'))));
    }
}
