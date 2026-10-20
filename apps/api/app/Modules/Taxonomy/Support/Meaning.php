<?php

namespace App\Modules\Taxonomy\Support;

use App\Modules\Enrichment\Contracts\ProductMeaning;
use App\Modules\Taxonomy\Models\TaxonomyAssignment;

/** A product's or a post's tags with what each rests on, for the scan screen. Code only. */
final class Meaning implements ProductMeaning
{
    public function of(string $type, string $id, string $locale): array
    {
        $order = [TaxonomyAssignment::KEPT => 0, TaxonomyAssignment::REVIEW => 1, TaxonomyAssignment::REJECTED => 2];

        return TaxonomyAssignment::query()->where('subject_type', $type)->where('subject_id', $id)->with('tag.group')->get()
            ->filter(fn (TaxonomyAssignment $a): bool => $a->tag !== null && $a->tag->group !== null)
            ->map(fn (TaxonomyAssignment $a): array => [
                'group' => $a->tag->group->label($locale),
                'label' => $locale === 'he' ? $a->tag->label_he : $a->tag->label_en,
                'weight' => (float) $a->weight,
                'source' => (string) $a->source,
                'evidence' => $a->evidence,
                'explicit' => (bool) $a->explicit,
                'status' => (string) $a->status,
                'note' => $a->check_note,
            ])
            ->sort(fn (array $a, array $b): int => [$order[$a['status']] ?? 3, -$a['weight'], $a['label']] <=> [$order[$b['status']] ?? 3, -$b['weight'], $b['label']])
            ->values()->all();
    }
}
