<?php

namespace App\Modules\Enrichment\Scanning;

use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentFact;

/**
 * What the store's own writing says buyers should know, as titles: the guides and posts that teach
 * how to choose ("איך בוחרים מקדחה"), and the store's pages (shipping, returns). The knowledge
 * base is built before the structure of the catalog, because a store's guides show what its buyers
 * ask and how it words things; the vocabulary writer reads them beside the counts, never instead.
 *
 * Code only, titles only, a few of them: a title costs a few tokens and says most of what a guide
 * is about. For a branch, the guides about it first: those a model or code tied to a category of
 * the branch, then those whose title names the branch. Inside the shop's tenant.
 */
final class KnowledgeEvidence
{
    /** The kinds of writing that teach a buyer. */
    public const GUIDE_KINDS = ['buying_guide', 'how_to', 'material_guide', 'project_idea', 'product_review'];

    public const MAX_GUIDES = 12;

    public const MAX_PAGES = 10;

    /** Facts that stand: a reading a person or a check dropped does not. */
    private const STANDING = [FactStatus::Approved, FactStatus::AwaitingReview, FactStatus::AwaitingEscalation, FactStatus::NeedsPerson];

    /**
     * @param  array{root: string, name: string}|null  $branch  the branch the vocabulary is for; null for the whole store
     * @return array{guides: list<array{title: string, kind: string}>, pages: list<string>}
     */
    public static function of(?array $branch = null): array
    {
        $contents = CatalogContent::query()->whereNull('removed_at')
            ->whereIn('type', [CatalogContent::POST, CatalogContent::PAGE])
            ->where('external_id', 'not like', CatalogContent::OWN_PREFIX.'%')
            ->orderBy('external_id')->get(['id', 'type', 'external_id', 'title']);

        if ($contents->isEmpty()) {
            return ['guides' => [], 'pages' => []];
        }

        $facts = EnrichmentFact::query()->whereIn('content_id', $contents->pluck('id'))->whereIn('kind', [FactKind::ContentKind, FactKind::Category])
            ->whereIn('status', self::STANDING)->orderBy('id')->get(['content_id', 'kind', 'value_text']);
        $kinds = [];
        $categories = [];
        foreach ($facts as $fact) {
            if ($fact->kind === FactKind::ContentKind) {
                $kinds[$fact->content_id] ??= (string) $fact->value_text;
            } else {
                $categories[$fact->content_id][] = (string) $fact->value_text;
            }
        }

        $branchIds = $branch === null ? [] : self::branchIds((string) $branch['root']);
        $guides = [];
        $pages = [];

        foreach ($contents as $content) {
            $kind = $kinds[$content->id] ?? null;
            $title = mb_substr(trim((string) $content->title), 0, 140);

            if ($title === '') {
                continue;
            }

            if ($kind === 'store_page' || ($kind === null && $content->type === CatalogContent::PAGE)) {
                $pages[] = $title;

                continue;
            }

            if ($kind !== null && ! in_array($kind, self::GUIDE_KINDS, true)) {
                continue;
            }

            // For a branch: tied to one of its categories (2), or its title names the branch (1); else not about it.
            $relevance = 0;
            if ($branch !== null) {
                $relevance = array_intersect($categories[$content->id] ?? [], $branchIds) !== [] ? 2 : (mb_stripos($title, (string) $branch['name']) !== false ? 1 : 0);

                if ($relevance === 0) {
                    continue;
                }
            }

            $guides[] = ['relevance' => $relevance, 'title' => $title, 'kind' => $kind ?? 'post'];
        }

        usort($guides, fn (array $a, array $b): int => [$b['relevance'], $a['title']] <=> [$a['relevance'], $b['title']]);

        return [
            'guides' => array_map(fn (array $g): array => ['title' => $g['title'], 'kind' => $g['kind']], array_slice($guides, 0, self::MAX_GUIDES)),
            'pages' => array_slice(array_values(array_unique($pages)), 0, self::MAX_PAGES),
        ];
    }

    /** @return list<string> external ids of a category and everything below it */
    private static function branchIds(string $root): array
    {
        $categories = CatalogCategory::query()->active()->get();
        $category = $categories->firstWhere('external_id', $root);

        return $category?->branchExternalIds($categories) ?? [$root];
    }
}
