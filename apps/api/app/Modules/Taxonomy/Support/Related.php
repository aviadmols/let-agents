<?php

namespace App\Modules\Taxonomy\Support;

use App\Core\Facades\Settings;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Taxonomy\Models\TaxonomyAssignment;
use App\Modules\Taxonomy\Models\TaxonomyTag;

/**
 * Products like one product by the tags they share, weighed: each kept tag by how central it is to
 * the product (its weight), by how far it is trusted (said outright in the store's words most, then
 * inferred from them, the picture, knowledge: taxonomy.trust_*), and by how rare it is in the store (a
 * tag on almost every product, such as "ישראל" in an Israeli bookshop, tells little; "חטופים" much); a
 * tag's broader tag counted too, at half its weight. What the store itself says, its categories and
 * its own tags, counts as tags of their own (taxonomy.store_*_percent). The likeness is the cosine of
 * the two weighed sets. Code only, no model; call inside the shop's tenant.
 */
final class Related
{
    /** A broader tag counts at this share of the narrower one's weight. */
    private const PARENT_SHARE = 0.5;

    /** @var array<string, array<string, float>>|null product id => tag id => weight */
    private ?array $vectors = null;

    /** @var array<string, float> tag id => how rare it is */
    private array $rarity = [];

    /** @var array<string, string> the store's categories and tags, by their key here, => their names */
    private array $names = [];

    /**
     * @return list<array{id: string, score: float, shared: list<string>}> the likest first
     */
    public function to(string $productId, int $limit = 6): array
    {
        $vectors = $this->vectors();
        $mine = $vectors[$productId] ?? [];

        if ($mine === []) {
            return [];
        }

        $labels = TaxonomyTag::query()->whereIn('id', array_keys($mine))->pluck('label_he', 'id')->all() + $this->names;
        $scores = [];

        foreach ($vectors as $id => $theirs) {
            if ($id === $productId) {
                continue;
            }
            $shared = array_intersect_key($mine, $theirs);
            if ($shared === []) {
                continue;
            }
            $dot = 0.0;
            foreach ($shared as $tag => $weight) {
                $dot += $weight * $theirs[$tag] * $this->rarity[$tag] ** 2;
            }
            $score = $dot / ($this->norm($mine) * $this->norm($theirs));
            if ($score > 0) {
                arsort($shared);
                $scores[] = ['id' => (string) $id, 'score' => round($score, 3), 'shared' => array_values(array_map(fn ($t): string => (string) ($labels[$t] ?? $t), array_keys($shared)))];
            }
        }

        usort($scores, fn (array $a, array $b): int => [$b['score'], $a['id']] <=> [$a['score'], $b['id']]);

        return array_slice($scores, 0, $limit);
    }

    /** @return array<string, array<string, float>> */
    private function vectors(): array
    {
        if ($this->vectors !== null) {
            return $this->vectors;
        }

        $parents = TaxonomyTag::query()->whereNotNull('parent_id')->pluck('parent_id', 'id')->all();
        $vectors = [];
        $percent = fn (string $key): float => (float) Settings::get('taxonomy.'.$key) / 100;
        // how far a tag is trusted by where it was read from: the store's words saying it outright most
        $trust = ['explicit' => 1.0, 'catalog' => $percent('trust_inferred_percent'), 'picture' => $percent('trust_picture_percent'), 'knowledge' => $percent('trust_knowledge_percent')];

        foreach (TaxonomyAssignment::query()->where('subject_type', TaxonomyAssignment::PRODUCT)->where('status', TaxonomyAssignment::KEPT)
            ->orderBy('id')->get(['subject_id', 'tag_id', 'weight', 'source', 'explicit']) as $a) {
            $weight = $a->weight * ($a->explicit ? $trust['explicit'] : ($trust[$a->source] ?? $trust['knowledge']));
            $vectors[$a->subject_id][$a->tag_id] = max($vectors[$a->subject_id][$a->tag_id] ?? 0, $weight);
            if (isset($parents[$a->tag_id])) {
                $parent = $parents[$a->tag_id];
                $vectors[$a->subject_id][$parent] = max($vectors[$a->subject_id][$parent] ?? 0, $weight * self::PARENT_SHARE);
            }
        }

        // what the store itself says of its products, its categories and its own tags, as tags of their
        // own; one that holds almost every product weighs almost nothing by its rarity ("ספרים")
        $categories = $percent('store_categories_percent');
        $storeTags = $percent('store_tags_percent');
        foreach (CatalogProduct::query()->whereNull('removed_at')->whereIn('id', array_keys($vectors))->with('categories:id,name')->get(['id', 'payload']) as $product) {
            foreach ($product->categories as $category) {
                $vectors[$product->id]['category:'.$category->id] = $categories;
                $this->names['category:'.$category->id] = $category->name;
            }
            foreach ((array) ($product->payload['tags'] ?? []) as $tag) {
                $name = trim(is_array($tag) ? (string) ($tag['name'] ?? '') : (string) $tag);
                if ($name !== '') {
                    $vectors[$product->id]['store_tag:'.mb_strtolower($name)] = $storeTags;
                    $this->names['store_tag:'.mb_strtolower($name)] = $name;
                }
            }
        }

        $count = count($vectors);
        $holding = [];
        foreach ($vectors as $tags) {
            foreach (array_keys($tags) as $tag) {
                $holding[$tag] = ($holding[$tag] ?? 0) + 1;
            }
        }
        foreach ($holding as $tag => $n) {
            $this->rarity[$tag] = log(($count + 1) / ($n + 1)) + 0.1;
        }

        return $this->vectors = $vectors;
    }

    /** @param  array<string, float>  $tags */
    private function norm(array $tags): float
    {
        $sum = 0.0;
        foreach ($tags as $tag => $weight) {
            $sum += ($weight * $this->rarity[$tag]) ** 2;
        }

        return max(sqrt($sum), 1e-9);
    }
}
