<?php

namespace App\Modules\Enrichment\Support;

use App\Core\Facades\Features;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactOrigin;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentBatch;
use App\Modules\Enrichment\Models\EnrichmentBatchItem;
use App\Modules\Enrichment\Models\EnrichmentFact;

/**
 * Saves facts from one answer. With enrichment.auto_approve off for the shop, nothing is
 * approved without a person: what checks would approve waits in the person queue instead.
 */
final class FactWriter
{
    /**
     * Earlier facts about the same subject that no person decided are replaced by a new reading.
     * Facts code wrote on its own stay: a model reading never replaces them.
     */
    public function supersedePrevious(string $column, string $subjectId, array $kinds): void
    {
        EnrichmentFact::query()
            ->where($column, $subjectId)
            ->whereIn('kind', array_map(fn (FactKind $k): string => $k->value, $kinds))
            ->where('status', '!=', FactStatus::Superseded)
            ->where('origin', '!=', FactOrigin::Code)
            ->whereNull('decided_by')
            ->update(['status' => FactStatus::Superseded, 'updated_at' => now()]);
    }

    /** Whether a person of the shop gave a value (or "does not apply") for this detail of this subject. */
    public static function teamSaid(string $column, string $subjectId, string $key): bool
    {
        return EnrichmentFact::query()
            ->where($column, $subjectId)
            ->where('key', $key)
            ->where('origin', FactOrigin::Person)
            ->whereIn('status', [FactStatus::Approved, FactStatus::NotApplicable])
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $values  kind, key, value_number, value_text, unit, quote, origin, status, status_reason
     */
    public function write(EnrichmentBatch $batch, EnrichmentBatchItem $item, string $column, string $model, array $values): EnrichmentFact
    {
        $status = $values['status'];

        if ($status === FactStatus::Approved && ! Features::enabled('enrichment.auto_approve', $batch->shop_id)) {
            $status = FactStatus::NeedsPerson;
        }

        $reason = $values['status_reason'] ?? null;

        // What the shop's team said of this detail stands: a later reading is kept for the log, not used.
        if (in_array($values['kind'], [FactKind::Choice, FactKind::Spec, FactKind::Flag], true)
            && self::teamSaid($column, $item->subject_id, (string) $values['key'])) {
            [$status, $reason] = [FactStatus::Superseded, 'team_value'];
        }

        return EnrichmentFact::query()->create([
            'shop_id' => $batch->shop_id,
            $column => $item->subject_id,
            'vocabulary_id' => $batch->vocabulary_id,
            'kind' => $values['kind'],
            'key' => $values['key'],
            'value_number' => $values['value_number'] ?? null,
            'value_text' => $values['value_text'] ?? null,
            'unit' => $values['unit'] ?? null,
            'quote' => isset($values['quote']) ? mb_substr((string) $values['quote'], 0, 500) : null,
            'origin' => $values['origin'] ?? FactOrigin::CodeAndModel,
            'status' => $status,
            'status_reason' => $reason,
            'batch_id' => $batch->id,
            'input_hash' => $item->input_hash,
            'model' => mb_substr($model, 0, 120),
        ]);
    }
}
