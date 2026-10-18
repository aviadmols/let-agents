<?php

namespace App\Modules\Enrichment\Tasks;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Enums\ItemStatus;
use App\Modules\Enrichment\Enums\TaskType;
use App\Modules\Enrichment\Enums\Verdict;
use App\Modules\Enrichment\Models\EnrichmentBatch;
use App\Modules\Enrichment\Models\EnrichmentBatchItem;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Scanning\Measurement;
use App\Modules\Enrichment\Scanning\ProductDigest;
use App\Modules\Enrichment\Scanning\TextCondenser;
use Illuminate\Support\Collection;

/**
 * A second model checks claims the first reading could not settle: tier 1 is the small model
 * for everything waiting, tier 2 a stronger one only for what tier 1 was unsure about. What
 * tier 2 is unsure about goes to a person.
 */
final class FactReviewTask implements AgentTask
{
    public function type(): TaskType
    {
        return TaskType::FactReview;
    }

    public static function waitingStatus(int $tier): FactStatus
    {
        return $tier >= 2 ? FactStatus::AwaitingEscalation : FactStatus::AwaitingReview;
    }

    public function requests(EnrichmentBatch $batch, int $limit): iterable
    {
        $tier = max(1, $batch->review_tier);
        $subject = ($batch->scope['subject'] ?? 'product') === 'content' ? 'content' : 'product';
        $column = $subject === 'content' ? 'content_id' : 'product_id';
        $maxChars = (int) Settings::get('enrichment.max_agent_text_chars', $batch->shop_id);

        $subjectIds = EnrichmentFact::query()
            ->where('status', self::waitingStatus($tier))
            ->whereNotNull($column)
            ->when($subject === 'product' && $batch->vocabulary_id !== null, fn ($q) => $q->where('vocabulary_id', $batch->vocabulary_id))
            ->distinct()
            ->orderBy($column)
            ->limit($limit)
            ->pluck($column);

        foreach ($subjectIds as $subjectId) {
            $facts = EnrichmentFact::query()
                ->where($column, $subjectId)
                ->where('status', self::waitingStatus($tier))
                // A full, fixed order: the claim list is part of the request ID on every database.
                ->orderBy('kind')->orderBy('key')->orderBy('value_text')->orderBy('value_number')->orderBy('quote')
                ->get();

            $subjectModel = $subject === 'content' ? CatalogContent::query()->find($subjectId) : CatalogProduct::query()->find($subjectId);

            if ($subjectModel === null || $facts->isEmpty()) {
                continue;
            }

            $claims = [];
            $claimIds = [];
            foreach ($facts->values() as $i => $fact) {
                $claimId = 'f'.($i + 1);
                $claimIds[$claimId] = $fact->id;
                $claims[] = [$claimId, $fact->kind->value, $fact->key, $this->claimValue($fact), (string) $fact->quote];
            }

            // Highlights were written from longer text (see ProductHighlightsTask): the checker reads the same text.
            $highlights = $facts->contains(fn (EnrichmentFact $f): bool => in_array($f->kind, [FactKind::Highlight, FactKind::Summary], true));
            $request = [
                'id' => $subjectModel->external_id,
                'title' => $subjectModel->title,
                'text' => $highlights
                    ? $this->text($subjectModel, (int) Settings::get('enrichment.max_highlight_text_chars', $batch->shop_id), $this->boilerplate($facts->first(fn (EnrichmentFact $f): bool => in_array($f->kind, [FactKind::Highlight, FactKind::Summary], true))), true)
                    : $this->text($subjectModel, $maxChars, $this->boilerplate($facts->first())),
                'claims' => $claims,
            ];

            $inputHash = hash('sha256', $batch->prompt_hash.'|'.json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            yield new TaskRequest(
                customId: 'fr'.$tier.'-'.($subject === 'content' ? 'c' : 'p').$subjectModel->external_id.'-'.substr($inputHash, 0, 10),
                subjectType: $subject,
                subjectId: $subjectId,
                inputHash: $inputHash,
                request: $request,
                context: ['claims' => $claimIds, 'tier' => $tier],
            );
        }
    }

    public function isStale(EnrichmentBatch $batch, EnrichmentBatchItem $item): bool
    {
        // Each claim is checked on its own when the answer is applied.
        return false;
    }

    public function apply(EnrichmentBatch $batch, EnrichmentBatchItem $item, array $output, string $model): ItemOutcome
    {
        if ((string) ($output['id'] ?? '') !== (string) $item->request['id']) {
            return ItemOutcome::rejected(['wrong_id']);
        }

        $verdicts = is_array($output['verdicts'] ?? null) ? $output['verdicts'] : null;

        if ($verdicts === null) {
            return ItemOutcome::rejected(['no_verdicts']);
        }

        $tier = (int) ($item->context['tier'] ?? 1);
        $autoApprove = Features::enabled('enrichment.auto_approve', $batch->shop_id);
        $problems = [];
        $decided = 0;

        /** @var Collection<string, EnrichmentFact> $facts */
        $facts = EnrichmentFact::query()->whereKey(array_values((array) $item->context['claims']))->get()->keyBy('id');

        foreach ((array) $item->context['claims'] as $claimId => $factId) {
            $fact = $facts->get($factId);
            $verdict = Verdict::tryFrom((string) ($verdicts[$claimId] ?? ''));

            if ($verdict === null) {
                $problems[] = "no_verdict:{$claimId}";

                continue;
            }

            if ($fact === null || $fact->status !== self::waitingStatus($tier)) {
                $problems[] = "already_decided:{$claimId}";

                continue;
            }

            $status = match ($verdict) {
                Verdict::Ok => $autoApprove ? FactStatus::Approved : FactStatus::NeedsPerson,
                Verdict::Wrong => FactStatus::Rejected,
                Verdict::Unsure => $tier >= 2 ? FactStatus::NeedsPerson : FactStatus::AwaitingEscalation,
            };

            $fact->forceFill([
                'status' => $status,
                'status_reason' => $verdict === Verdict::Wrong ? 'review_wrong' : ($verdict === Verdict::Unsure ? 'review_unsure' : $fact->status_reason),
                'review_verdict' => $verdict,
                'review_model' => mb_substr($model, 0, 120),
                'review_tier' => $tier,
                'review_batch_id' => $batch->id,
            ])->save();

            $decided++;
        }

        return new ItemOutcome(ItemStatus::Applied, $decided, count($problems), $problems);
    }

    private function claimValue(EnrichmentFact $fact): string
    {
        return match ($fact->kind) {
            FactKind::Spec => Measurement::compact((float) $fact->value_number).' '.$fact->unit,
            default => (string) $fact->value_text,
        };
    }

    /** @param list<string> $boilerplate */
    private function text(CatalogProduct|CatalogContent $subject, int $maxChars, array $boilerplate, bool $headingsWithContent = false): string
    {
        $sections = $subject instanceof CatalogProduct
            ? ProductDigest::sections($subject)
            : [$subject->title, (string) $subject->body];

        return (new TextCondenser)->condense($sections, $maxChars, $boilerplate, $headingsWithContent)['text'];
    }

    /**
     * The repeated lines removed when the facts were first read, so the checker sees the same text.
     *
     * @return list<string>
     */
    private function boilerplate(?EnrichmentFact $fact): array
    {
        $batch = $fact?->batch_id === null ? null : EnrichmentBatch::query()->find($fact->batch_id);

        return array_values((array) ($batch?->scope['boilerplate'] ?? []));
    }
}
