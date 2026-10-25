<?php

namespace App\Modules\Catalog\Support;

use App\Modules\Catalog\Contracts\ProductKinds;
use App\Modules\Catalog\Models\CatalogProduct;
use Illuminate\Support\Facades\Cache;

/**
 * Kinds from the store's own product types, categories and tags. How many active products carry
 * each is counted once per catalog change and kept, so a request reads no more than the products
 * it asks about.
 */
final class CatalogProductKinds implements ProductKinds
{
    /** A tag on more than this share of the products says nothing about kind ("כל המוצרים", the brand). */
    private const TAG_MAX_SHARE = 0.4;

    /** A title's first word on more than this share of the products is the store's, not a kind ("מארז" in a gift shop). */
    private const HEAD_MAX_SHARE = 0.6;

    /** A series may run through much of a small store ("אייקוני - כדורים פורחים" on half of it), never all of it. */
    private const SERIES_MAX_SHARE = 0.75;

    /**
     * A campaign, not a kind: a sale, a season, a date, a collection, what is new or in fashion ("sale 20%", "july20",
     * "חזרה לגן", "Top 10", "חדש באתר", "CLUB 20%", "כל מה שחם", "BACK TO SCHOOL", "טרנדים").
     */
    private const CAMPAIGN = '/\d|%|\b(sale|off|new|top|last|chance|outlet|clearance|black friday|club|back to school|trend\w*|collection)\b|מבצע|הנחה|סייל|חדש|חזרה ל|כל מה ש|טרנד|קולקצי/iu';

    public function campaign(string $name): bool
    {
        return preg_match(self::CAMPAIGN, $name) === 1;
    }

    /** Words a store uses for a print, a collection or a series. */
    private const SERIES_WORDS = '/הדפס|דוגמ|קולקצי|סדרת|אייקוני|print|pattern|collection|series/iu';

    public function marks(CatalogProduct $product): array
    {
        $counts = $this->counts((string) $product->shop_id);
        $own = self::own($product);

        if ($own['type'] !== '') {
            return ['type:'.$own['type']];
        }

        // A Hebrew title opens with what the product is ("תיק גב", "נעלי סניקרס", "חולצת טי"): a kind when a few share
        // it and it is not most of the store. Before the categories, which may be whom it is for ("גברים", "ילדים").
        $heads = $counts['head'] ?? [];
        if ($own['head'] !== '' && ($heads[$own['head']] ?? 0) >= 2 && ($heads[$own['head']] ?? 0) <= $counts['products'] * self::HEAD_MAX_SHARE) {
            return ['head:'.$own['head']];
        }

        if ($own['categories'] !== []) {
            return array_map(fn (string $c): string => 'category:'.$c, $own['categories']);
        }

        $limit = max(2, (int) floor($counts['products'] * self::TAG_MAX_SHARE));

        return array_values(array_map(fn (string $t): string => 'tag:'.$t, array_filter(
            $own['tags'],
            fn (string $t): bool => ($counts['tag'][$t] ?? 0) <= $limit && $t !== $own['title'] && preg_match(self::CAMPAIGN, $t) !== 1,
        )));
    }

    public function sameKind(CatalogProduct $product, array $externalIds): array
    {
        $marks = $this->marks($product);

        if ($marks === [] || $externalIds === []) {
            return $externalIds;
        }

        $others = CatalogProduct::query()->whereNull('removed_at')->whereIn('external_id', $externalIds)->get(['external_id', 'title', 'payload'])
            ->keyBy(fn (CatalogProduct $p): string => (string) $p->external_id);

        return array_values(array_filter($externalIds, function (string $id) use ($others, $marks): bool {
            $other = $others->get($id);

            return $other !== null && array_intersect($marks, self::allMarks($other)) !== [];
        }));
    }

    public function hasLike(CatalogProduct $product): bool
    {
        $counts = $this->counts((string) $product->shop_id);

        foreach ($this->marks($product) as $mark) {
            [$kind, $value] = explode(':', $mark, 2);
            if (($counts[$kind][$value] ?? 0) >= 2) {
                return true;
            }
        }

        return false;
    }

    public function series(CatalogProduct $product): ?array
    {
        $counts = $this->counts((string) $product->shop_id);
        $own = self::own($product);
        $limit = max(2, min($counts['products'] - 1, (int) floor($counts['products'] * self::SERIES_MAX_SHARE)));
        $best = null;

        foreach ($product->payload['tags'] ?? [] as $tag) {
            $tag = trim((string) $tag);
            $key = mb_strtolower($tag);
            $count = $counts['tag'][$key] ?? 0;
            // A name of its own ("אייקוני - כדורים פורחים"), not the bare word ("קולקציות").
            if ($count >= 2 && $count <= $limit && $key !== $own['title'] && str_contains($tag, ' ') && preg_match(self::SERIES_WORDS, $tag) === 1 && preg_match(self::CAMPAIGN, $tag) !== 1
                && ($best === null || $count > $best['count'] + 1)) {
                $best = ['tag' => $tag, 'count' => $count - 1];
            }
        }

        return $best;
    }

    /**
     * Every mark another product could match with: its type, categories and tags, whatever its
     * own kind is read from.
     *
     * @return list<string>
     */
    private static function allMarks(CatalogProduct $product): array
    {
        $own = self::own($product);

        return [
            ...($own['type'] !== '' ? ['type:'.$own['type']] : []),
            ...($own['head'] !== '' ? ['head:'.$own['head']] : []),
            ...array_map(fn (string $c): string => 'category:'.$c, $own['categories']),
            ...array_map(fn (string $t): string => 'tag:'.$t, $own['tags']),
        ];
    }

    /** @return array{type: string, head: string, categories: list<string>, tags: list<string>, title: string} */
    private static function own(CatalogProduct $product): array
    {
        $clean = fn (mixed $v): string => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $v)));
        // the title's first word, when it is Hebrew (an English title opens with its model, "Old Skool Backpack")
        $first = (string) (preg_split('/[\s,\-–—|:]+/u', trim((string) $product->title), 2)[0] ?? '');
        $head = preg_match('/^\p{Hebrew}{2,}$/u', $first) === 1 ? $first : '';

        return [
            'type' => $clean($product->payload['product_type'] ?? ''),
            'head' => $head,
            'categories' => array_values(array_unique(array_filter(array_map(fn (array $path): string => $clean(end($path) ?: ''), $product->categoryPaths())))),
            'tags' => array_values(array_unique(array_filter(array_map($clean, (array) ($product->payload['tags'] ?? []))))),
            'title' => $clean($product->title),
        ];
    }

    /**
     * How many active products carry each type, category and tag, kept until the catalog changes.
     *
     * @return array{products: int, type: array<string, int>, head: array<string, int>, category: array<string, int>, tag: array<string, int>}
     */
    private function counts(string $shopId): array
    {
        $active = CatalogProduct::query()->whereNull('removed_at');
        $version = '2|'.$active->count().'|'.$active->max('updated_at');

        return Cache::remember('catalog:kinds:'.$shopId.':'.md5($version), now()->addHours(12), function (): array {
            $counts = ['products' => 0, 'type' => [], 'head' => [], 'category' => [], 'tag' => []];

            CatalogProduct::query()->whereNull('removed_at')->select(['id', 'title', 'payload'])->orderBy('id')
                ->chunk(500, function ($products) use (&$counts): void {
                    foreach ($products as $product) {
                        $own = self::own($product);
                        $counts['products']++;
                        if ($own['type'] !== '') {
                            $counts['type'][$own['type']] = ($counts['type'][$own['type']] ?? 0) + 1;
                        }
                        if ($own['head'] !== '') {
                            $counts['head'][$own['head']] = ($counts['head'][$own['head']] ?? 0) + 1;
                        }
                        foreach ($own['categories'] as $category) {
                            $counts['category'][$category] = ($counts['category'][$category] ?? 0) + 1;
                        }
                        foreach ($own['tags'] as $tag) {
                            $counts['tag'][$tag] = ($counts['tag'][$tag] ?? 0) + 1;
                        }
                    }
                });

            return $counts;
        });
    }
}
