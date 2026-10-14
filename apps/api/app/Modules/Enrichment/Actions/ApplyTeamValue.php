<?php

namespace App\Modules\Enrichment\Actions;

use App\Core\Tenancy\TenantContext;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactOrigin;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Enrichment\Scanning\Units;
use App\Modules\Enrichment\Vocabulary\VocabularyDefinition;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * What the shop's team says of one detail of one product: a value, or that it does not apply. It is
 * a fact like the others (so search, the widget and the answers use it) with origin Person, and it
 * stands: no later reading by code or a model replaces it (ReadProductsInCode and FactWriter step
 * aside). It is kept here only. Nothing is written back to the store (ADR 0005).
 *
 * Inside the shop's tenant. A value is checked against the vocabulary: a choice is one of its
 * values, a number is inside the attribute's range, in the unit of its dimension.
 */
final class ApplyTeamValue
{
    /**
     * The team's value, saved. $evidence says where it came from when it is more than the person's
     * word (the sources a web check found and the person approved).
     *
     * @param  array<string, mixed>|null  $evidence
     * @return array{ok: bool, problem: string|null} problem is a key of enrichment::missing.problems
     */
    public function set(string $productId, string $vocabularyId, string $attribute, string|int|float|array $value, ?array $evidence = null): array
    {
        $checked = $this->check($vocabularyId, $attribute, $value);

        if (! $checked['ok']) {
            return ['ok' => false, 'problem' => $checked['problem']];
        }

        $this->write($productId, $vocabularyId, $attribute, $checked['kind'], $checked['rows'], FactStatus::Approved, $evidence);

        return ['ok' => true, 'problem' => null];
    }

    /**
     * Whether a value is one the vocabulary allows for the detail, and what it would be saved as:
     * a choice is one of its values, a number is inside the range, in the unit of its dimension.
     * Nothing is saved.
     *
     * @return array{ok: bool, problem: string|null, kind: FactKind|null, rows: list<array{value_text: string|null, value_number: float|null, unit: string|null}>}
     */
    public function check(string $vocabularyId, string $attribute, string|int|float|array $value): array
    {
        [$definition, $entry, $problem] = $this->attribute($vocabularyId, $attribute);
        $refuse = fn (string $why): array => ['ok' => false, 'problem' => $why, 'kind' => null, 'rows' => []];

        if ($problem !== null) {
            return $refuse($problem);
        }

        $kind = ($entry['type'] ?? 'number') === 'enum' ? FactKind::Choice : FactKind::Spec;
        $rows = [];

        if ($kind === FactKind::Choice) {
            $valid = array_column((array) ($entry['values'] ?? []), 'key');
            $values = array_values(array_unique(array_map('strval', (array) $value)));

            if ($values === [] || array_diff($values, $valid) !== [] || (count($values) > 1 && ! $definition->isMultiple($attribute))) {
                return $refuse('not_a_choice');
            }

            foreach ($values as $one) {
                $rows[] = ['value_text' => $one, 'value_number' => null, 'unit' => null];
            }
        } else {
            $number = is_array($value) ? null : self::number($value);

            if ($number === null) {
                return $refuse('not_a_number');
            }

            if ($number < (float) $entry['min'] || $number > (float) $entry['max']) {
                return $refuse('out_of_range');
            }

            $rows[] = ['value_text' => null, 'value_number' => $number, 'unit' => Units::CANONICAL[$entry['dimension']] ?? null];
        }

        return ['ok' => true, 'problem' => null, 'kind' => $kind, 'rows' => $rows];
    }

    /** "This does not apply to this product": no longer a gap, and not a fact about the product. */
    public function notApplicable(string $productId, string $vocabularyId, string $attribute): array
    {
        [, $entry, $problem] = $this->attribute($vocabularyId, $attribute);

        if ($problem !== null) {
            return ['ok' => false, 'problem' => $problem];
        }

        $kind = ($entry['type'] ?? 'number') === 'enum' ? FactKind::Choice : FactKind::Spec;
        $this->write($productId, $vocabularyId, $attribute, $kind, [['value_text' => 'not_applicable', 'value_number' => null, 'unit' => null]], FactStatus::NotApplicable);

        return ['ok' => true, 'problem' => null];
    }

    /** Takes the team's word back: the detail is a gap again, and the next reading may fill it. */
    public function clear(string $productId, string $attribute): void
    {
        EnrichmentFact::query()->where('product_id', $productId)->where('key', $attribute)->where('origin', FactOrigin::Person)
            ->whereIn('status', [FactStatus::Approved, FactStatus::NotApplicable])
            ->update(['status' => FactStatus::Superseded, 'updated_at' => now()]);
    }

    /**
     * @param  list<array{value_text: string|null, value_number: float|null, unit: string|null}>  $rows
     * @param  array<string, mixed>|null  $evidence
     */
    private function write(string $productId, string $vocabularyId, string $attribute, FactKind $kind, array $rows, FactStatus $status, ?array $evidence = null): void
    {
        DB::transaction(function () use ($productId, $vocabularyId, $attribute, $kind, $rows, $status, $evidence): void {
            // Whatever was said before of this detail, by code, a model or the team, is replaced.
            EnrichmentFact::query()->where('product_id', $productId)->where('key', $attribute)
                ->whereIn('kind', [FactKind::Choice, FactKind::Spec, FactKind::Flag])
                ->where('status', '!=', FactStatus::Superseded)
                ->update(['status' => FactStatus::Superseded, 'updated_at' => now()]);

            foreach ($rows as $row) {
                EnrichmentFact::query()->create($row + [
                    'shop_id' => app(TenantContext::class)->require(),
                    'product_id' => $productId,
                    'vocabulary_id' => $vocabularyId,
                    'kind' => $kind,
                    'key' => $attribute,
                    'origin' => FactOrigin::Person,
                    'status' => $status,
                    'status_reason' => 'team',
                    'input_hash' => hash('sha256', 'team|'.$productId.'|'.$attribute.'|'.json_encode($row)),
                    'model' => 'team',
                    'confidence' => 1,
                    'evidence' => ['source' => 'team'] + ($evidence ?? []),
                    'decided_by' => Auth::id(),
                    'decided_at' => now(),
                ]);
            }
        });
    }

    /** @return array{0: VocabularyDefinition|null, 1: array<string, mixed>|null, 2: string|null} */
    private function attribute(string $vocabularyId, string $attribute): array
    {
        $vocabulary = EnrichmentVocabulary::query()->find($vocabularyId);
        $definition = $vocabulary?->definition();
        $entry = $definition?->attribute($attribute);

        if ($entry === null) {
            return [null, null, 'unknown_attribute'];
        }

        if (! in_array($entry['type'], ['enum', 'number'], true)) {
            return [$definition, $entry, 'unsupported'];
        }

        return [$definition, $entry, null];
    }

    /** "3,5" and "3.5" are both 3.5; anything else is not a number. */
    public static function number(string|int|float $value): ?float
    {
        $text = trim(str_replace(',', '.', (string) $value));

        return is_numeric($text) ? (float) $text : null;
    }
}
