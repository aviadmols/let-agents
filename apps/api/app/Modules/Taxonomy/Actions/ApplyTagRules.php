<?php

namespace App\Modules\Taxonomy\Actions;

use App\Modules\Taxonomy\Models\TaxonomyAssignment;

/**
 * Today's rules on the tags already kept, in code: what a rule now refuses is taken out, with why,
 * as AssignTags would refuse it now. A tag kept under an older rule never stays because it came
 * first. Call inside the shop's tenant.
 *
 * - From the picture, only in a group whose tags a picture may say (TaxonomyGroup::from_picture):
 *   a colour, a shape, a style; never what a stone or a metal is.
 */
final class ApplyTagRules
{
    public const RULE = 'rule';

    /** @return int how many kept tags were taken out */
    public function apply(): int
    {
        $out = 0;

        TaxonomyAssignment::query()->where('status', TaxonomyAssignment::KEPT)->where('source', 'picture')
            ->whereHas('tag.group', fn ($q) => $q->where('from_picture', false))
            ->orderBy('id')->chunkById(500, function ($chunk) use (&$out): void {
                foreach ($chunk as $a) {
                    $a->forceFill(['status' => TaxonomyAssignment::REJECTED, 'checked' => self::RULE, 'check_note' => (string) __('taxonomy::runs.not_from_picture')])->save();
                    $out++;
                }
            });

        return $out;
    }
}
