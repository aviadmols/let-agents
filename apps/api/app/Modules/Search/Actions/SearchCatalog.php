<?php

namespace App\Modules\Search\Actions;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Retrieval\Contracts\PictureContent;
use App\Modules\Retrieval\Contracts\SemanticSearch;
use App\Modules\Search\Models\SearchResolution;
use App\Modules\Search\Support\HebrewSearch;
use App\Modules\Search\Support\LoadedIndex;

/**
 * One search in a shop: by spelling and by meaning, merged, grouped by what was found.
 *
 *   1. spelling   HebrewSearch over the nightly index: typos, plural and singular, full and
 *                 defective spelling, an English keyboard. No model, a few milliseconds.
 *   2. meaning    Retrieval's index by meaning, and its pictures where they are indexed, for words that say what the shopper wants
 *                 rather than what the product is called ("משהו לחבר קרשים"). One small
 *                 embedding per new wording, kept, so a repeated query costs nothing.
 *   3. merge      records that hold every word as typed come first, in spelling order; the
 *                 rest are merged by reciprocal rank, so a record both ways found rises.
 *   4. group      products (what was in stock last night first), guides and pages, categories.
 *   5. count      the query, normalized, is counted for the day, with how many results it had.
 *
 * Nothing about the shopper is kept.
 */
final class SearchCatalog
{
    public const GROUPS = ['answer', 'product', 'content', 'category'];

    /** Pictures compared with a tagged photo: every scanned picture of a shop this size. */
    private const PHOTO_ALL = 5000;

    /** Products a tagged photo returns, for the shopper to narrow by tag in the browser. */
    private const PHOTO_TAGGED = 60;

    /** Products a tag brings, before the picture orders them. */
    private const PHOTO_PER_TAG = 40;

    /** Products of the asked-about kind put first for a question. */
    private const SUBJECT_PRODUCTS = 12;

    /** Reciprocal rank constant: how much a lower rank still counts. */
    private const RRF_K = 60;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly SemanticSearch $semantic,
        private readonly CountSearch $counter,
        private readonly ReadPhotoTags $photoTags,
    ) {}

    /**
     * @param  list<string>|null  $only  groups to return; null for all
     * @return array{query: string, total: int, semantic: bool, groups: array<string, list<array<string, mixed>>>, counts: array<string, int>}
     */
    /**
     * @param  bool  $meaning  false while a shopper is still typing: spelling only, code only, no vector
     */
    public function handle(string $shopId, string $raw, ?array $only = null, bool $count = true, ?int $perGroup = null, bool $meaning = true): array
    {
        return $this->tenant->run($shopId, function () use ($shopId, $raw, $only, $count, $perGroup, $meaning): array {
            $query = HebrewSearch::normalize(mb_substr($raw, 0, 120));
            $empty = ['query' => $query, 'total' => 0, 'semantic' => false, 'groups' => array_fill_keys(self::GROUPS, []), 'counts' => array_fill_keys(self::GROUPS, 0)];
            $index = $query === '' ? null : LoadedIndex::for($shopId);

            if ($index === null) {
                return $empty;
            }

            $spelling = HebrewSearch::search($index['engine'], $query);

            // A sentence ("מה הכי טוב למברגה ביתית וזולה") holds words no product has: search
            // what it is about instead, so a question finds the products it asks about.
            if ($spelling === [] && str_contains($query, ' ') && (str_contains($raw, '?') || HebrewSearch::asks($query))) {
                $about = HebrewSearch::aboutWords($index['engine'], $query);
                $spelling = $about !== '' && $about !== $query ? HebrewSearch::search($index['engine'], $about) : [];
            }
            $byMeaning = $meaning;
            $meaning = $byMeaning ? $this->meaning($shopId, $query, $index['records']) : [];
            $pictures = $byMeaning ? $this->pictures($shopId, $query, $index['records']) : [];
            $pinned = $this->resolved($query, $index['records']);
            // A question about a kind of product ("איזה עץ…") shows that kind first: products of the
            // categories named after it, before products that only mention the word (a bracket
            // "לעמודי עץ"). The assistant then picks from them too.
            $subject = str_contains($query, ' ') && (str_contains($raw, '?') || HebrewSearch::asks($query))
                ? $this->subjectProducts($index, $query, $spelling)
                : [];
            $ranked = $this->merge($spelling, [$meaning, $pictures], [...$pinned, ...$subject]);
            $perGroup ??= (int) Settings::get('search.results_per_group', $shopId);

            $groups = array_fill_keys(self::GROUPS, []);

            foreach ($ranked as $id) {
                $record = $index['records'][$id] ?? null;
                $type = $record['t'] ?? null;

                if ($record !== null && isset($groups[$type])) {
                    $groups[$type][] = $record;
                }
            }

            // What was in stock last night ahead of what was not, otherwise in rank order.
            $groups['product'] = array_merge(
                array_values(array_filter($groups['product'], fn (array $r): bool => ($r['s'] ?? 0) === 1)),
                array_values(array_filter($groups['product'], fn (array $r): bool => ($r['s'] ?? 0) !== 1)),
            );

            $counts = array_map('count', $groups);
            $total = array_sum($counts);

            if ($count) {
                $this->counter->search($shopId, $query, $total);
            }

            foreach ($groups as $type => $records) {
                $groups[$type] = $only === null || in_array($type, $only, true)
                    ? array_map(fn (array $r): array => $this->present($r), array_slice($records, 0, $perGroup))
                    : [];
            }

            return ['query' => $query, 'total' => $total, 'semantic' => $meaning !== [] || $pictures !== [], 'resolved' => $pinned !== [], 'subject' => $subject !== [], 'groups' => $groups, 'counts' => $counts];
        });
    }

    /**
     * Products of the kind a question asks about: the categories whose name starts with its
     * subject ("עץ אורן", "עצים" for "עץ"; not "צבע לעץ"), what spelling already found of them
     * first, then the rest of them in stock first. At most SUBJECT_PRODUCTS record ids.
     *
     * @param  array{engine: array<string, mixed>, records: array<string, array<string, mixed>>}  $index
     * @param  list<array{id: string}>  $spelling
     * @return list<string>
     */
    private function subjectProducts(array $index, string $query, array $spelling): array
    {
        $subject = HebrewSearch::subject($index['engine'], $query);

        if ($subject === null) {
            return [];
        }

        $close = array_keys(HebrewSearch::closeWords($index['engine'], $subject));
        $categories = [];

        foreach ($index['records'] as $record) {
            $first = explode(' ', HebrewSearch::normalize((string) ($record['title'] ?? '')))[0];

            if (($record['t'] ?? null) === 'category' && $first !== '' && in_array($first, $close, true)) {
                $categories[] = substr((string) $record['id'], 2);
            }
        }

        if ($categories === []) {
            return [];
        }

        $members = CatalogProduct::query()->active()
            ->whereHas('categories', fn ($q) => $q->whereIn('external_id', $categories))
            ->orderByDesc('in_stock')->orderBy('title')
            ->limit(200)->pluck('external_id')->map(fn ($id): string => 'p:'.$id)->all();
        $members = array_values(array_filter($members, fn (string $id): bool => isset($index['records'][$id])));
        $found = array_values(array_intersect(array_column($spelling, 'id'), $members));

        return array_slice(array_values(array_unique([...$found, ...$members])), 0, self::SUBJECT_PRODUCTS);
    }

    /**
     * Products like one in the results: by its picture when the shop's pictures are scanned, by
     * the meaning of its name and description otherwise. Both read vectors already stored, so no
     * model is asked; what was in stock last night comes first, and the product itself never.
     *
     * @return list<array<string, mixed>>
     */
    public function similar(string $shopId, string $externalId, int $limit): array
    {
        return $this->tenant->run($shopId, function () use ($shopId, $externalId, $limit): array {
            $index = LoadedIndex::for($shopId);
            $product = CatalogProduct::query()->whereNull('removed_at')->where('external_id', $externalId)->first(['id']);

            if ($index === null || $product === null || $limit < 1) {
                return [];
            }

            $ids = array_column($this->semantic->picturesReady() ? $this->semantic->lookAlike($product->id, $limit * 2) : [], 'external_id');

            if ($ids === []) {
                $ids = array_column($this->semantic->similarTo('product', $product->id, ['product'], $limit * 2), 'external_id');
            }

            $records = [];

            foreach ($ids as $id) {
                $record = $index['records']['p:'.$id] ?? null;

                if ($record !== null && (string) $id !== $externalId) {
                    $records[] = $record;
                }
            }

            usort($records, fn (array $a, array $b): int => ($b['s'] ?? 0) <=> ($a['s'] ?? 0));

            return array_map(fn (array $r): array => $this->present($r), array_slice($records, 0, $limit));
        });
    }

    /**
     * Products that look like a photo the shopper uploaded, most alike first, each with how alike
     * in percent. One picture embedding, or none when the same photo was searched before. The
     * photo is not kept; the search is counted, without it.
     *
     * @return array{total: int, groups: array{product: list<array<string, mixed>>}, searched: bool}
     */
    public function photo(string $shopId, string $mime, string $bytes): array
    {
        return $this->tenant->run($shopId, function () use ($shopId, $mime, $bytes): array {
            $index = LoadedIndex::for($shopId);
            $floor = (float) Settings::get('search.photo_min_similarity');

            // What the photo shows, in the shop's own categories and words.
            $tags = $index === null ? null : $this->photoTags->handle($shopId, $mime, $bytes);
            $perGroup = (int) Settings::get('search.results_per_group', $shopId);

            // The photo was read and shows nothing the shop sells (a child, a landscape): nothing
            // looks like it, however close a white-background picture of a screw may score.
            if ($tags === []) {
                $this->counter->photo($shopId, 0);

                return ['total' => 0, 'groups' => ['product' => []], 'tags' => [], 'nothing' => true, 'searched' => true];
            }

            if ($tags !== null) {
                // A scene (a pergola, a deck) looks like no single product picture, so the picture
                // scores are flat and the nearest pictures are noise. The tags say what to show; the
                // picture only orders it. Every product carries which tags it belongs to, so the
                // shopper narrows by a tag without leaving the photo.
                $hits = $this->semantic->picturesNearPhoto($shopId, $mime, $bytes, self::PHOTO_ALL);
                // What the photo shows, against what each product's picture shows.
                $content = app(PictureContent::class)->picturesNearWords($shopId, implode(', ', array_column($tags, 'title')), self::PHOTO_ALL);
                $products = $this->taggedProducts($index, $tags, $hits, $shopId, $content);
                $this->counter->photo($shopId, count($products));

                return ['total' => count($products), 'groups' => ['product' => $products], 'tags' => array_map(fn (array $t): array => array_diff_key($t, ['id' => 0, 'main' => 0]), $tags), 'searched' => true];
            }

            $hits = $index === null ? [] : $this->semantic->picturesNearPhoto($shopId, $mime, $bytes, max(48, (int) Settings::get('search.semantic_results')));
            $hits = array_values(array_filter($hits, fn (array $hit): bool => isset($index['records']['p:'.$hit['external_id']]) && $hit['similarity'] >= $floor));
            $products = [];

            foreach ($this->photoOrder($hits, $shopId) as $hit) {
                $products[] = $this->present($index['records']['p:'.$hit['external_id']]) + ['match' => (int) round(max(0, min(1, $hit['similarity'])) * 100)];
            }

            $products = array_slice($products, 0, $perGroup);
            $this->counter->photo($shopId, count($products));

            return ['total' => count($products), 'groups' => ['product' => $products], 'tags' => [], 'searched' => $hits !== [] || $index !== null];
        });
    }

    /**
     * The products a tagged photo is about: each tag's products (a category's own, or what its
     * words find), in stock when the shop asks for it, ordered by how alike their picture is to
     * the photo and varied by brand. Each carries `tags`, the positions of the tags it belongs to.
     *
     * @param  array{engine: array<string, mixed>, records: array<string, array<string, mixed>>}  $index
     * @param  list<array{title: string, kind: string, id?: string}>  $tags
     * @param  list<array{external_id: string, similarity: float}>  $hits
     * @return list<array<string, mixed>>
     */
    private function taggedProducts(array $index, array $tags, array $hits, string $shopId, array $content = []): array
    {
        $members = [];

        foreach ($tags as $at => $tag) {
            $ids = isset($tag['id'])
                ? CatalogProduct::query()->active()
                    ->whereHas('categories', fn ($q) => $q->where('external_id', substr((string) $tag['id'], 2)))
                    ->limit(self::PHOTO_PER_TAG * 3)->pluck('external_id')->map(fn ($id): string => (string) $id)->all()
                : array_map(fn (array $hit): string => substr($hit['id'], 2), array_slice(array_values(array_filter(
                    HebrewSearch::search($index['engine'], $tag['title']),
                    fn (array $hit): bool => str_starts_with($hit['id'], 'p:'),
                )), 0, self::PHOTO_PER_TAG));

            foreach ($ids as $id) {
                if (isset($index['records']['p:'.$id])) {
                    $members[$id][] = $at;
                }
            }
        }

        if ($members === []) {
            return [];
        }

        // Looks and content together: each picture's visual likeness and its description's, weighed by
        // retrieval.content_weight; a picture with only one of them is judged by that one.
        $looks = array_column($hits, 'similarity', 'external_id');
        $about = array_column($content, 'similarity', 'external_id');
        $weight = (float) Settings::get('retrieval.content_weight');
        $similarity = [];
        foreach (array_unique([...array_keys($looks), ...array_keys($about)]) as $id) {
            $v = $looks[$id] ?? null;
            $c = $about[$id] ?? null;
            $similarity[$id] = $v !== null && $c !== null ? (1 - $weight) * $v + $weight * $c : (float) ($v ?? $c);
        }
        $rows = CatalogProduct::query()->whereIn('external_id', array_keys($members))->get(['external_id', 'brand', 'in_stock'])->keyBy('external_id');
        $candidates = array_map(fn ($id): array => ['external_id' => (string) $id, 'similarity' => (float) ($similarity[$id] ?? 0.0)], array_keys($members));

        if ((bool) Settings::get('search.photo_in_stock_only', $shopId)) {
            $stocked = array_values(array_filter($candidates, fn (array $c): bool => (bool) $rows->get($c['external_id'])?->in_stock));
            $candidates = $stocked !== [] ? $stocked : $candidates;
        }

        // What the shopper photographed first (the main tag's products), then by likeness, then by
        // how many of the photo's tags a product shares. Likeness alone would put a scanned tool box
        // above a drill whose picture is not scanned yet.
        $main = array_keys(array_filter($tags, fn (array $t): bool => ($t['main'] ?? false) === true));
        $isMain = fn (array $c): int => array_intersect($members[$c['external_id']], $main) !== [] ? 1 : 0;
        usort($candidates, fn (array $a, array $b): int => $isMain($b) <=> $isMain($a) ?: $b['similarity'] <=> $a['similarity'] ?: count($members[$b['external_id']]) <=> count($members[$a['external_id']]));
        $brandOf = fn (array $c): string => mb_strtolower(trim((string) $rows->get($c['external_id'])?->brand)) ?: 'id:'.$c['external_id'];

        $out = [];
        foreach (array_slice($this->varied($candidates, $brandOf), 0, self::PHOTO_TAGGED) as $c) {
            $out[] = $this->present($index['records']['p:'.$c['external_id']]) + array_filter([
                'match' => $c['similarity'] > 0 ? (int) round(min(1, $c['similarity']) * 100) : null,
                'tags' => $members[$c['external_id']],
            ], fn ($v): bool => $v !== null);
        }

        return $out;
    }

    /**
     * What a photo shows, in the order a shopper wants it. Picture scores sit close together and
     * favour a brand's colours, so the score alone shows six Makita tools and a box of screws.
     *
     *   1. kind      products in the categories of the best matches, whatever their brand,
     *                then what is only close to the best match (search.photo_relative_gap)
     *   2. variety   no more than two of one brand in a row while other brands are left
     *   3. stock     only what is in stock (search.photo_in_stock_only), unless nothing is
     *
     * @param  list<array{external_id: string, similarity: float}>  $hits  most alike first
     * @return list<array{external_id: string, similarity: float}>
     */
    private function photoOrder(array $hits, string $shopId): array
    {
        if ($hits === []) {
            return [];
        }

        $products = CatalogProduct::query()->whereIn('external_id', array_column($hits, 'external_id'))
            ->with('categories:id')->get(['id', 'external_id', 'brand', 'in_stock'])->keyBy('external_id');
        $categoriesOf = fn (array $hit): array => $products->get($hit['external_id'])?->categories->pluck('id')->all() ?? [];

        usort($hits, fn (array $a, array $b): int => $b['similarity'] <=> $a['similarity']);
        $best = $hits[0]['similarity'];
        $close = $best - (float) Settings::get('search.photo_relative_gap');
        $kind = array_unique(array_merge(...array_map($categoriesOf, array_slice($hits, 0, 3))));

        $sameKind = array_values(array_filter($hits, fn (array $hit): bool => array_intersect($categoriesOf($hit), $kind) !== []));
        $onlyClose = array_values(array_filter($hits, fn (array $hit): bool => $hit['similarity'] >= $close && array_intersect($categoriesOf($hit), $kind) === []));

        if ((bool) Settings::get('search.photo_in_stock_only', $shopId)) {
            $inStock = fn (array $hit): bool => (bool) $products->get($hit['external_id'])?->in_stock;
            $stocked = array_values(array_filter($sameKind, $inStock));
            $stockedClose = array_values(array_filter($onlyClose, $inStock));

            if ($stocked !== [] || $stockedClose !== []) {
                [$sameKind, $onlyClose] = [$stocked, $stockedClose];
            }
        }

        $brandOf = fn (array $hit): string => mb_strtolower(trim((string) $products->get($hit['external_id'])?->brand)) ?: 'id:'.$hit['external_id'];

        return array_merge($this->varied($sameKind, $brandOf), $this->varied($onlyClose, $brandOf));
    }

    /**
     * The same hits, most alike first, but never a third of one brand in a row while another
     * brand is still waiting.
     *
     * @param  list<array{external_id: string, similarity: float}>  $hits
     * @return list<array{external_id: string, similarity: float}>
     */
    private function varied(array $hits, callable $brandOf): array
    {
        $out = [];

        while ($hits !== []) {
            $last = array_map($brandOf, array_slice($out, -2));
            $repeat = count($last) === 2 && $last[0] === $last[1] ? $last[0] : null;
            $at = 0;

            if ($repeat !== null) {
                foreach ($hits as $i => $hit) {
                    if ($brandOf($hit) !== $repeat) {
                        $at = $i;
                        break;
                    }
                }
            }

            $out[] = $hits[$at];
            array_splice($hits, $at, 1);
        }

        return $out;
    }

    /**
     * Record ids found by meaning, nearest first.
     *
     * @param  array<string, array<string, mixed>>  $records
     * @return list<string>
     */
    private function meaning(string $shopId, string $query, array $records): array
    {
        if (! Features::enabled('search.semantic', $shopId) || mb_strlen($query) < (int) Settings::get('search.semantic_min_chars')) {
            return [];
        }

        $limit = (int) Settings::get('search.semantic_results');
        $floor = (float) Settings::get('search.semantic_min_similarity');
        $ids = [];

        foreach ($this->semantic->nearText($shopId, $query, ['product', 'content'], $limit) as $hit) {
            $id = ($hit['source'] === 'product' ? 'p:' : 'c:').$hit['external_id'];

            if ($hit['similarity'] >= $floor && isset($records[$id])) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Products whose picture matches the words, nearest first: "חולצת פסים" finds striped shirts
     * whose names never say so. Only where the shop's pictures are indexed.
     *
     * @param  array<string, array<string, mixed>>  $records
     * @return list<string>
     */
    private function pictures(string $shopId, string $query, array $records): array
    {
        if (! Features::enabled('search.pictures', $shopId) || mb_strlen($query) < (int) Settings::get('search.semantic_min_chars')) {
            return [];
        }

        $floor = (float) Settings::get('search.picture_min_similarity');
        $ids = [];

        foreach ($this->semantic->picturesNearText($shopId, $query, (int) Settings::get('search.semantic_results')) as $hit) {
            $id = 'p:'.$hit['external_id'];

            if ($hit['similarity'] >= $floor && isset($records[$id])) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * What the night resolved for this exact query, when a shopper's search found nothing: the
     * products and guides a model matched and a model of another family accepted. Shown first.
     *
     * @param  array<string, array<string, mixed>>  $records
     * @return list<string>
     */
    private function resolved(string $query, array $records): array
    {
        $resolution = SearchResolution::query()->where('query_hash', hash('sha256', $query))->where('status', SearchResolution::RESOLVED)->first();

        if ($resolution === null) {
            return [];
        }

        $ids = array_merge(
            array_map(fn ($id): string => 'p:'.$id, (array) $resolution->products),
            array_map(fn ($id): string => 'c:'.$id, (array) $resolution->content),
        );

        return array_values(array_filter($ids, fn (string $id): bool => isset($records[$id])));
    }

    /**
     * @param  list<array{id: string, title: string, score: float, exact: bool}>  $spelling
     * @param  list<list<string>>  $others  ranked ids by meaning, by picture
     * @param  list<string>  $pinned  what the night resolved for this query, first of all
     * @return list<string>
     */
    private function merge(array $spelling, array $others, array $pinned = []): array
    {
        $exact = [];
        $scores = [];

        foreach ($spelling as $rank => $hit) {
            if ($hit['exact']) {
                $exact[] = $hit['id'];

                continue;
            }

            $scores[$hit['id']] = ($scores[$hit['id']] ?? 0) + 1 / (self::RRF_K + $rank);
        }

        foreach ($others as $list) {
            foreach ($list as $rank => $id) {
                if (! in_array($id, $exact, true)) {
                    $scores[$id] = ($scores[$id] ?? 0) + 1 / (self::RRF_K + $rank);
                }
            }
        }

        arsort($scores);

        return array_values(array_unique([...$pinned, ...$exact, ...array_map('strval', array_keys($scores))]));
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function present(array $record): array
    {
        [$prefix, $externalId] = explode(':', (string) $record['id'], 2);

        return array_filter([
            'id' => $record['id'],
            'type' => $record['t'],
            'external_id' => $externalId,
            'title' => $record['title'],
            'url' => $record['url'] ?? null,
            'image' => $record['img'] ?? null,
            'kind' => $record['kind'] ?? null,
            'products' => $record['n'] ?? null,
            'buy' => isset($record['buy']) ? $record['buy'] === 1 : null,
            'answer' => $record['ans'] ?? null,
            'sources' => $record['src'] ?? null,
        ], fn ($value): bool => $value !== null);
    }
}
