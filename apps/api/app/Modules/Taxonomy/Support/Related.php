<?php

namespace App\Modules\Taxonomy\Support;

use App\Modules\Taxonomy\Models\TaxonomyAssignment;
use App\Modules\Taxonomy\Models\TaxonomyTag;

/**
 * Products like one product by the tags they share, weighed: each kept tag by how central it is to
 * the product (its weight) and by how rare it is in the store (a tag on almost every product, such as
 * "ישראל" in an Israeli bookshop, tells little; "חטופים" much), and a tag's broader tag counted too, at
 * half its weight ("כלכלה התנהגותית" is also "כלכלה"). The likeness is the cosine of the two weighed
 * tag sets. Code only, no model; call inside the shop's tenant.
 */
final class Related
{
    /** A broader tag counts at this share of the narrower one's weight. */
    private const PARENT_SHARE = 0.5;

    /** @var array<string, array<string, float>>|null product id => tag id => weight */
    private ?array $vectors = null;

    /** @var array<string, float> tag id => how rare it is */
    private array $rarity = [];

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

        $labels = TaxonomyTag::query()->whereIn('id', array_keys($mine))->pluck('label_he', 'id');
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

        foreach (TaxonomyAssignment::query()->where('subject_type', TaxonomyAssignment::PRODUCT)->where('status', TaxonomyAssignment::KEPT)
            ->orderBy('id')->get(['subject_id', 'tag_id', 'weight']) as $a) {
            $vectors[$a->subject_id][$a->tag_id] = max($vectors[$a->subject_id][$a->tag_id] ?? 0, $a->weight);
            if (isset($parents[$a->tag_id])) {
                $parent = $parents[$a->tag_id];
                $vectors[$a->subject_id][$parent] = max($vectors[$a->subject_id][$parent] ?? 0, $a->weight * self::PARENT_SHARE);
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
