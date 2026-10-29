<?php

namespace App\Modules\Taxonomy\Actions;

use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Events\CatalogProductsChanged;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Taxonomy\Models\TaxonomyAssignment;
use App\Modules\Taxonomy\Models\TaxonomyGroup;
use App\Modules\Taxonomy\Models\TaxonomyPair;
use App\Modules\Taxonomy\Models\TaxonomyRule;
use App\Modules\Taxonomy\Models\TaxonomyTag;
use App\Modules\Taxonomy\Support\Bank;
use Illuminate\Support\Facades\Cache;

/**
 * A rule's plan, applied as the team approved it (ShopRules::decide): a tag added is the team's, kept and never
 * undone by tagging again or by the rules; a tag taken off is the team's "out"; two products kept apart are never
 * alike again, two put together always are (TaxonomyPair, read by Related and the search's similar products). The
 * alike by tags are read afresh, and the search index is built again for the products changed. Code only.
 */
final class ApplyRule
{
    /** The cache of the alike by tags (RelatedProducts) is keyed by this, and turned over when a rule is applied. */
    public const RELATED_VERSION = 'taxonomy:related:v:';

    public function handle(TaxonomyRule $rule, ?int $userId): void
    {
        $shopId = app(TenantContext::class)->require();
        $changes = (array) ($rule->plan['changes'] ?? []);
        $products = CatalogProduct::query()->whereNull('removed_at')
            ->whereIn('external_id', array_values(array_unique(array_merge(...array_map(fn (array $c): array => [(string) $c['external_id'], ...(array) ($c['apart_ids'] ?? []), ...(array) ($c['alike_ids'] ?? [])], $changes ?: [['external_id' => '']])))))
            ->get(['id', 'external_id'])->keyBy('external_id');
        $tags = [];
        foreach (Bank::groups() as $group) {
            /** @var TaxonomyGroup $group */
            foreach ($group->tags as $tag) {
                /** @var TaxonomyTag $tag */
                $tags[$group->key.':'.$tag->key] = (string) $tag->id;
            }
        }
        $version = Bank::version();
        $evidence = mb_substr(__('taxonomy::runs.rule_evidence', ['text' => $rule->text]), 0, 400);
        $touched = [];

        foreach ($changes as $change) {
            $product = $products->get((string) ($change['external_id'] ?? ''));
            if ($product === null) {
                continue;
            }
            $touched[(string) $product->external_id] = true;

            foreach ((array) ($change['add_keys'] ?? []) as $key) {
                if (! isset($tags[$key])) {
                    continue;
                }
                TaxonomyAssignment::query()->updateOrCreate(
                    ['subject_type' => TaxonomyAssignment::PRODUCT, 'subject_id' => (string) $product->id, 'tag_id' => $tags[$key]],
                    ['shop_id' => $shopId, 'source' => TaxonomyAssignment::TEAM_SOURCE, 'evidence' => $evidence, 'confidence' => 1.0, 'weight' => 1.0, 'explicit' => true,
                        'status' => TaxonomyAssignment::KEPT, 'checked' => TaxonomyAssignment::TEAM_KEPT, 'check_note' => null, 'input_hash' => 'rule:'.$rule->id, 'bank_version' => $version, 'run_id' => $rule->run_id],
                );
            }

            foreach ((array) ($change['remove_keys'] ?? []) as $key) {
                if (! isset($tags[$key])) {
                    continue;
                }
                TaxonomyAssignment::query()->where('subject_type', TaxonomyAssignment::PRODUCT)->where('subject_id', (string) $product->id)->where('tag_id', $tags[$key])
                    ->update(['status' => TaxonomyAssignment::REJECTED, 'checked' => TaxonomyAssignment::TEAM_OUT, 'check_note' => mb_substr(__('taxonomy::runs.team_out'), 0, 200), 'updated_at' => now()]);
            }

            foreach ([TaxonomyPair::APART => (array) ($change['apart_ids'] ?? []), TaxonomyPair::ALIKE => (array) ($change['alike_ids'] ?? [])] as $kind => $ids) {
                foreach ($ids as $id) {
                    $other = $products->get((string) $id);
                    if ($other === null || (string) $other->id === (string) $product->id) {
                        continue;
                    }
                    $touched[(string) $other->external_id] = true;
                    // in both directions, and the opposite word, if the team once said it, withdrawn
                    foreach ([[(string) $product->id, (string) $other->id], [(string) $other->id, (string) $product->id]] as [$a, $b]) {
                        TaxonomyPair::query()->where('product_id', $a)->where('other_id', $b)->where('kind', '!=', $kind)->delete();
                        TaxonomyPair::query()->updateOrCreate(['product_id' => $a, 'other_id' => $b, 'kind' => $kind], ['shop_id' => $shopId, 'rule_id' => $rule->id]);
                    }
                }
            }
        }

        $rule->forceFill(['status' => TaxonomyRule::APPLIED, 'decided_by' => $userId, 'decided_at' => now()])->save();
        Cache::increment(self::RELATED_VERSION.$shopId);

        if ($touched !== []) {
            event(new CatalogProductsChanged($shopId, array_keys($touched)));
        }
    }
}
