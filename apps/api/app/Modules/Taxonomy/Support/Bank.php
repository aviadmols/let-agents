<?php

namespace App\Modules\Taxonomy\Support;

use App\Modules\Taxonomy\Models\TaxonomyGroup;
use App\Modules\Taxonomy\Models\TaxonomyTag;
use Illuminate\Support\Collection;

/**
 * A store's tag bank as code reads it: its version (the highest any group or tag came in with),
 * its groups with their active tags, and the bank as the tagger reads it, the same text every
 * call so the provider can cache it. Call inside the shop's tenant.
 */
final class Bank
{
    public static function version(): int
    {
        return max((int) TaxonomyGroup::query()->max('version'), (int) TaxonomyTag::query()->max('version'));
    }

    /** @return Collection<int, TaxonomyGroup> with their active tags, in order */
    public static function groups(): Collection
    {
        return TaxonomyGroup::query()->orderBy('position')->orderBy('key')
            ->with(['tags' => fn ($q) => $q->where('status', TaxonomyTag::ACTIVE)->orderBy('key')])->get();
    }

    /** The bank as the tagger reads it, after its instructions. */
    public static function prefix(Collection $groups): string
    {
        $bank = $groups->map(fn (TaxonomyGroup $g): array => [
            'group' => $g->key,
            'name' => $g->label_he.' / '.$g->label_en,
            'kind' => $g->kind,
            'multiple' => $g->multiple,
            'picture' => $g->from_picture,
            'scope' => array_values((array) $g->scope),
            'tags' => $g->tags->map(fn (TaxonomyTag $t): array => array_filter([
                'tag' => $t->key,
                'name' => $t->label_he.' / '.$t->label_en,
                'words' => array_values((array) $t->words),
                'about' => $t->about,
                // the broader tag it is part of: an item of it is of that too, so the narrower one is given
                'part_of' => $t->parent_id === null ? null : $g->tags->firstWhere('id', $t->parent_id)?->key,
            ]))->values()->all(),
        ])->values()->all();

        return "# The bank\n\n".json_encode($bank, JSON_UNESCAPED_UNICODE);
    }
}
