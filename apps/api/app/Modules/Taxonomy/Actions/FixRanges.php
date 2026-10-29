<?php

namespace App\Modules\Taxonomy\Actions;

use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Events\CatalogProductsChanged;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Taxonomy\Models\TaxonomyAssignment;
use App\Modules\Taxonomy\Models\TaxonomyGroup;
use App\Modules\Taxonomy\Models\TaxonomyTag;
use App\Modules\Taxonomy\Support\Exact;

/**
 * The tags given before a range was read by its number (AssignTags does it as it tags): each range whose own passage's
 * number is outside of it moves to the range of its group that holds it ("6-Day" from "4–5 days" to "6–8 days"); one
 * the item has already goes. What the team decided stays as it left it. Code only; the moved products are told to the
 * search.
 */
final class FixRanges
{
    public function __construct(private readonly TenantContext $tenant) {}

    /** @return list<array{subject: string, from: string, to: string, evidence: string}> what moved */
    public function handle(string $shopId, bool $dry = false): array
    {
        return $this->tenant->run($shopId, function () use ($shopId, $dry): array {
            $groups = TaxonomyGroup::query()->where('kind', TaxonomyGroup::FACTUAL)->with('tags')->get();
            $tags = [];
            foreach ($groups as $group) {
                $tags[$group->id] = $group->tags->each(fn (TaxonomyTag $t) => $t->setRelation('group', $group))->keyBy('id')->all();
            }

            $moved = [];
            $products = [];
            TaxonomyAssignment::query()->whereIn('tag_id', collect($tags)->flatMap(fn (array $t): array => array_keys($t))->all())
                ->whereIn('status', [TaxonomyAssignment::KEPT, TaxonomyAssignment::REVIEW])
                ->where(fn ($q) => $q->whereNull('checked')->orWhereNotIn('checked', TaxonomyAssignment::TEAM))
                ->orderBy('id')->get()
                ->each(function (TaxonomyAssignment $a) use ($tags, $dry, &$moved, &$products): void {
                    $tag = collect($tags)->first(fn (array $t): bool => isset($t[$a->tag_id]))[$a->tag_id];
                    $fits = Exact::fitting($tag, $a->evidence, $tags[$tag->group_id]);
                    if ($fits === $tag) {
                        return;
                    }
                    $moved[] = ['subject' => $a->subject_type.':'.$a->subject_id, 'from' => $tag->label_en, 'to' => $fits->label_en, 'evidence' => (string) $a->evidence];
                    if ($a->subject_type === TaxonomyAssignment::PRODUCT) {
                        $products[$a->subject_id] = true;
                    }
                    if ($dry) {
                        return;
                    }
                    $has = TaxonomyAssignment::query()->where('subject_type', $a->subject_type)->where('subject_id', $a->subject_id)->where('tag_id', $fits->id)->exists();
                    $has ? $a->delete() : $a->forceFill(['tag_id' => $fits->id])->save();
                });

            if (! $dry && $products !== []) {
                $external = CatalogProduct::query()->whereIn('id', array_keys($products))->pluck('external_id')->map(fn ($id): string => (string) $id)->all();
                event(new CatalogProductsChanged($shopId, $external));
            }

            return $moved;
        });
    }
}
