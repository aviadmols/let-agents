<?php

namespace App\Modules\Assistant\Support;

use App\Modules\Assistant\Models\AssistantQuestionLink;
use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Catalog\Models\CatalogProduct;
use Illuminate\Support\Facades\DB;

/**
 * The questions the store's team linked to products (AssistantQuestionLink), found again for a question asked the same
 * way or worded like it, and the products they link to: those set by hand first, then their categories' and tags', in
 * stock first, spread across the prices so a broad question ("טבעת נישואין") is answered from the whole range. Code
 * only, inside the shop.
 */
final class QuestionLinks
{
    /** A link holds for a question that shares this share of its words, each way (a rewording, not another question). */
    private const SHARED = 0.6;

    /** The most products of a link offered to the answer. */
    public const MAX = 12;

    /** The link for a question: its own, else one asked with nearly the same words; null when none. */
    public static function for(string $question): ?AssistantQuestionLink
    {
        $own = AssistantQuestionLink::query()->where('question_key', Question::key($question))->first();
        if ($own !== null) {
            return $own->empty() ? null : $own;
        }

        $asked = Question::words($question);
        if ($asked === []) {
            return null;
        }
        $best = null;
        $bestShare = 0.0;
        foreach (AssistantQuestionLink::query()->limit(500)->get() as $link) {
            if ($link->empty()) {
                continue;
            }
            $words = Question::words($link->question);
            if ($words === []) {
                continue;
            }
            $both = count(array_intersect($asked, $words));
            $share = min($both / count($asked), $both / count($words));
            if ($share >= self::SHARED && $share > $bestShare) {
                [$best, $bestShare] = [$link, $share];
            }
        }

        return $best;
    }

    /**
     * The products a link offers, by external id: by hand first, then those of its categories and tags, in stock first,
     * spread across the prices, at most $max.
     *
     * @return list<string>
     */
    public static function products(AssistantQuestionLink $link, int $max = self::MAX): array
    {
        $byHand = array_values(array_unique(array_filter(array_map('strval', (array) $link->products))));
        $byHand = $byHand === [] ? [] : CatalogProduct::query()->active()->whereIn('external_id', $byHand)->pluck('external_id')->map(fn ($id): string => (string) $id)
            ->sortBy(fn (string $id): int => (int) array_search($id, $byHand, true))->values()->all();

        $ids = [];
        $categories = array_values(array_filter(array_map('strval', (array) $link->categories)));
        if ($categories !== []) {
            $inside = CatalogCategory::query()->whereIn('external_id', $categories)->pluck('id');
            $ids = DB::table('catalog_category_product')->whereIn('category_id', $inside)->pluck('product_id')->all();
        }
        $tags = array_values(array_filter(array_map(fn ($t): string => mb_strtolower(trim((string) $t)), (array) $link->tags)));
        $products = CatalogProduct::query()->active()
            ->where(fn ($q) => $q->whereIn('id', $ids)->when($tags !== [], fn ($q) => $q->orWhereNotNull('payload')))
            ->get(['id', 'external_id', 'price', 'in_stock', 'payload'])
            ->filter(fn (CatalogProduct $p): bool => in_array($p->id, $ids, true) || ($tags !== [] && array_intersect($tags, array_map(fn ($t): string => mb_strtolower(trim((string) $t)), (array) ($p->payload['tags'] ?? []))) !== []));

        $row = fn (CatalogProduct $p): array => ['id' => (string) $p->external_id, 'price' => $p->price];
        $spread = [
            ...self::spread($products->filter(fn (CatalogProduct $p): bool => (bool) $p->in_stock)->map($row)->values()->all(), $max),
            ...self::spread($products->filter(fn (CatalogProduct $p): bool => ! $p->in_stock)->map($row)->values()->all(), $max),
        ];

        return array_slice(array_values(array_unique([...$byHand, ...$spread])), 0, $max);
    }

    /**
     * Up to $max products evenly across their prices, the cheapest and the dearest among them: a broad question sees
     * the range, not the twelve nearest one price.
     *
     * @param  list<array{id: string, price: mixed}>  $products
     * @return list<string>
     */
    public static function spread(array $products, int $max): array
    {
        usort($products, fn (array $a, array $b): int => [(float) ($a['price'] ?? PHP_INT_MAX), $a['id']] <=> [(float) ($b['price'] ?? PHP_INT_MAX), $b['id']]);
        $n = count($products);
        if ($n <= $max) {
            return array_column($products, 'id');
        }
        $out = [];
        for ($i = 0; $i < $max; $i++) {
            $out[] = $products[(int) round($i * ($n - 1) / max(1, $max - 1))]['id'];
        }

        return array_values(array_unique($out));
    }
}
