<?php

namespace App\Modules\Taxonomy\Support;

use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Runs\Models\Run;
use App\Modules\Taxonomy\Actions\AssignTags;
use App\Modules\Taxonomy\Actions\BuildTagBank;
use App\Modules\Taxonomy\Actions\MergeTagProposals;
use App\Modules\Taxonomy\Models\TaxonomyAssignment;
use App\Modules\Taxonomy\Models\TaxonomyGroup;
use App\Modules\Taxonomy\Models\TaxonomyProposal;
use App\Modules\Taxonomy\Models\TaxonomySubject;
use App\Modules\Taxonomy\Models\TaxonomyTag;
use Illuminate\Support\Collection;

/**
 * How well a store was tagged, against cases a person reviewed (resources/evals/{shop slug}.json):
 * for a product by its title, the tags it must have ("expect": a pattern one of its kept tags'
 * words match) and those it must not ("reject": the negative cases, a hostages' tag on a book of
 * the war). And across the store: tags per product, where they were read from, what waits for
 * review or the checker took out, what code dropped and why, wordings the bank holds twice, and
 * what it all cost per product. Code only; call inside the shop's tenant.
 */
final class Evaluation
{
    /**
     * @param  array{cases?: list<array{title: string, expect?: list<string>, reject?: list<string>}>}  $gold
     * @return array<string, mixed>
     */
    public static function of(string $shopId, array $gold): array
    {
        $kept = TaxonomyAssignment::query()->where('subject_type', TaxonomyAssignment::PRODUCT)->where('status', TaxonomyAssignment::KEPT)
            ->with(['tag.group', 'tag.parent'])->get()->groupBy('subject_id');
        $cases = [];
        $expected = [0, 0];
        $rejected = [0, 0];

        foreach ((array) ($gold['cases'] ?? []) as $case) {
            $product = CatalogProduct::query()->whereNull('removed_at')->where('title', $case['title'])->first()
                ?? CatalogProduct::query()->whereNull('removed_at')->where('title', 'like', $case['title'].'%')->first();
            $words = $product === null ? [] : self::words($kept->get($product->id, collect()));
            $line = ['title' => $case['title'], 'found' => $product !== null, 'tags' => array_keys($words), 'missed' => [], 'wrong' => []];

            foreach ((array) ($case['expect'] ?? []) as $pattern) {
                $expected[1]++;
                self::matches($words, $pattern) ? $expected[0]++ : $line['missed'][] = $pattern;
            }
            foreach ((array) ($case['reject'] ?? []) as $pattern) {
                $rejected[1]++;
                self::matches($words, $pattern) ? $line['wrong'][] = $pattern : $rejected[0]++;
            }
            $cases[] = $line;
        }

        // products like a product by their weighed tags: those a person expects among the first few
        $related = [];
        $hits = [0, 0];
        $like = new Related;
        foreach ((array) ($gold['related'] ?? []) as $case) {
            $product = CatalogProduct::query()->whereNull('removed_at')->where('title', $case['title'])->first();
            $top = $product === null ? [] : $like->to($product->id, (int) ($case['within'] ?? 3));
            $titles = CatalogProduct::query()->whereIn('id', array_column($top, 'id'))->pluck('title', 'id');
            $shown = array_map(fn (array $r): string => (string) ($titles[$r['id']] ?? $r['id']).' ('.$r['score'].': '.implode(', ', array_slice($r['shared'], 0, 3)).')', $top);
            // what may rightly come up: a pattern any of several titles meets ("רובי": any ruby ring), at least
            // min of the first `within` of them; or titles named one by one
            if (isset($case['match'])) {
                $hits[1]++;
                $meet = count(array_filter($titles->all(), fn (string $t): bool => preg_match('/'.$case['match'].'/u', $t) === 1));
                $hits[0] += $meet >= (int) ($case['min'] ?? 1) ? 1 : 0;
            }
            foreach ((array) ($case['expect'] ?? []) as $title) {
                $hits[1]++;
                $found = in_array($title, $titles->all(), true);
                $hits[0] += $found ? 1 : 0;
            }
            $related[] = ['title' => $case['title'], 'top' => $shown, 'expect' => $case['match'] ?? (array) ($case['expect'] ?? [])];
        }

        $all = TaxonomyAssignment::query()->get(['subject_type', 'status', 'source', 'checked', 'explicit']);
        $productsTagged = TaxonomySubject::query()->where('subject_type', TaxonomyAssignment::PRODUCT)->count();
        $products = CatalogProduct::query()->whereNull('removed_at')->count();
        // this shop's runs only, of every kind: one that stopped half way paid for what it did
        $assignRuns = Run::query()->where('shop_id', $shopId)->where('action', AssignTags::ACTION)->get(['output', 'cost_usd']);
        $bankRuns = Run::query()->where('shop_id', $shopId)->whereIn('action', [BuildTagBank::ACTION, MergeTagProposals::ACTION])->get(['cost_usd']);
        $dropped = collect(['dropped_unknown', 'dropped_scope', 'dropped_unsupported', 'dropped_picture', 'unquoted'])->mapWithKeys(fn (string $k): array => [$k => (int) $assignRuns->sum(fn (Run $r): int => (int) ($r->output[$k] ?? 0))])->all();
        $given = max(1, $all->count() + array_sum(array_slice($dropped, 0, 4)));
        $assignCost = (float) $assignRuns->sum('cost_usd');

        return [
            'gold' => [
                'recall' => $expected[1] === 0 ? null : round($expected[0] / $expected[1] * 100, 1),
                'negative_pass' => $rejected[1] === 0 ? null : round($rejected[0] / $rejected[1] * 100, 1),
                'expected' => $expected[1],
                'negatives' => $rejected[1],
                'cases' => $cases,
                'related_hit' => $hits[1] === 0 ? null : round($hits[0] / $hits[1] * 100, 1),
                'related' => $related,
            ],
            'bank' => [
                'version' => Bank::version(),
                'groups' => TaxonomyGroup::query()->get(['key', 'kind', 'filter', 'searchable', 'recommend', 'scope'])->map(fn (TaxonomyGroup $g): array => $g->only(['key', 'kind', 'filter', 'searchable', 'recommend', 'scope']))->all(),
                'tags' => TaxonomyTag::query()->where('status', TaxonomyTag::ACTIVE)->count(),
                'duplicates' => self::duplicates(),
                'proposals_open' => TaxonomyProposal::query()->where('status', TaxonomyProposal::OPEN)->count(),
            ],
            'assigned' => [
                'products' => $products,
                'products_tagged' => $productsTagged,
                'tags_per_product' => $productsTagged === 0 ? 0 : round($all->where('subject_type', TaxonomyAssignment::PRODUCT)->where('status', TaxonomyAssignment::KEPT)->count() / $productsTagged, 2),
                'untagged_products' => TaxonomySubject::query()->where('subject_type', TaxonomyAssignment::PRODUCT)->whereNotIn('subject_id', TaxonomyAssignment::query()->where('status', TaxonomyAssignment::KEPT)->select('subject_id'))->count(),
                'status' => $all->countBy('status')->all(),
                'source' => $all->where('status', TaxonomyAssignment::KEPT)->countBy('source')->all(),
                'explicit' => $all->where('status', TaxonomyAssignment::KEPT)->countBy(fn (TaxonomyAssignment $a): string => $a->explicit ? 'explicit' : 'inferred')->all(),
                'checker_took_out' => $all->where('checked', 'taken_out')->count(),
                'dropped' => $dropped,
                // of what the tagger gave, what code would not keep for want of the store's own words
                'unsupported_percent' => round(($dropped['dropped_unsupported'] + $dropped['unquoted']) / $given * 100, 1),
            ],
            'cost' => [
                'bank_usd' => round((float) $bankRuns->sum('cost_usd'), 4),
                'assign_usd' => round($assignCost, 4),
                // what a product or post cost to tag, from the runs that finished: their cost over what they tagged
                'per_product_usd' => ($tagged = (int) $assignRuns->whereNotNull('cost_usd')->sum(fn (Run $r): int => (int) ($r->output['subjects'] ?? 0))) === 0
                    ? null : round((float) $assignRuns->whereNotNull('cost_usd')->sum('cost_usd') / $tagged, 5),
            ],
        ];
    }

    /**
     * Products to look at by hand: each with its kept tags, where each was read from and the evidence.
     *
     * @return list<array{title: string, tags: list<string>}>
     */
    public static function sample(int $count): array
    {
        $ids = TaxonomySubject::query()->where('subject_type', TaxonomyAssignment::PRODUCT)->inRandomOrder()->limit($count)->pluck('subject_id');
        $titles = CatalogProduct::query()->whereIn('id', $ids)->pluck('title', 'id');

        return $ids->map(fn ($id): array => [
            'title' => (string) ($titles[$id] ?? $id),
            'tags' => TaxonomyAssignment::query()->where('subject_id', $id)->with('tag.group')->get()
                ->map(fn (TaxonomyAssignment $a): string => sprintf('%s %s: %s [%s%s c%.2f w%.2f%s] %s', $a->status === TaxonomyAssignment::KEPT ? '+' : ($a->status === TaxonomyAssignment::REVIEW ? '?' : '−'),
                    $a->tag->group->label_he, $a->tag->label_he, $a->source, $a->explicit ? ' explicit' : '', $a->confidence, $a->weight, $a->check_note ? ' · '.$a->check_note : '', mb_substr((string) $a->evidence, 0, 90)))->all(),
        ])->values()->all();
    }

    /** @return array<string, true> every word a product's kept tags go by: group and tag labels, and the tag's other words */
    private static function words(Collection $assignments): array
    {
        $words = [];
        foreach ($assignments as $a) {
            $words[$a->tag->group->label_he.': '.$a->tag->label_he] = true;
            // a tag is also its broader tag ("כלכלה התנהגותית" is "כלכלה")
            foreach ([$a->tag->label_en, $a->tag->key, $a->tag->group->label_en, ...(array) $a->tag->words, $a->tag->parent?->label_he, $a->tag->parent?->label_en] as $w) {
                $words[(string) $w] = true;
            }
        }

        return $words;
    }

    /** @param  array<string, true>  $words */
    private static function matches(array $words, string $pattern): bool
    {
        foreach (array_keys($words) as $word) {
            if (preg_match('/'.$pattern.'/iu', (string) $word) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> wordings two active tags share, in a group or across groups */
    private static function duplicates(): array
    {
        $seen = [];
        $dupes = [];
        foreach (TaxonomyTag::query()->where('status', TaxonomyTag::ACTIVE)->with('group:id,key')->get() as $tag) {
            foreach (array_unique([Norm::label($tag->label_he), Norm::label($tag->label_en)]) as $norm) {
                if ($norm === '') {
                    continue;
                }
                if (isset($seen[$norm]) && $seen[$norm] !== $tag->id) {
                    $dupes[] = $tag->group->key.'/'.$tag->key.' = '.$norm;
                }
                $seen[$norm] = $tag->id;
            }
        }

        return array_values(array_unique($dupes));
    }
}
