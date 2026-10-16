<?php

namespace App\Modules\Assistant\Support;

use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Contracts\ReadsCards;
use App\Modules\Enrichment\Models\EnrichmentContentProduct;

/**
 * The page a shopper is asking about: a product, or a guide the store published.
 *
 * Both are answered the same way — from what the store itself says, checked before anyone sees
 * it — but they are not the same subject. A product is its checked facts and its description; an
 * article is its own text and the products it points to. Each carries the prompts written for it,
 * and the column its answers are saved under, so a question asked on a guide is never mixed with
 * a question asked on a product.
 */
final class Subject
{
    public const PRODUCT = 'product';

    public const ARTICLE = 'article';

    /**
     * @param  array<string, mixed>  $context  everything the writer may answer from
     * @param  array<string, mixed>  $brief  the little the scope check needs
     */
    private function __construct(
        public readonly string $kind,
        public readonly string $id,
        public readonly string $shopId,
        public readonly string $externalId,
        public readonly string $title,
        public readonly array $context,
        public readonly array $brief,
    ) {}

    /**
     * The product as its card says it (Enrichment cards: one JSON every model reads), without what the
     * shopper's assistant never speaks of: prices and stock, which the store's page says live. Its
     * options by name only ("מידה 6"), so "which sizes are there" has an answer.
     */
    public static function product(CatalogProduct $product, int $maxTextChars): self
    {
        $card = app(ReadsCards::class)->product($product->id) ?? [];
        $category = (string) ($card['category'] ?? implode(' > ', $product->categoryPaths()[0] ?? []));
        $related = [];
        foreach ((array) ($card['related'] ?? []) as $kind => $list) {
            $related[$kind] = array_values(array_map(fn (array $other): string => (string) $other['title'], (array) $list));
        }

        return new self(
            self::PRODUCT,
            $product->id,
            $product->shop_id,
            $product->external_id,
            $product->title,
            array_filter([
                'title' => (string) ($card['title'] ?? $product->title),
                'type' => $card['type'] ?? null,
                'brand' => $card['brand'] ?? null,
                'category' => $category,
                'options' => array_values(array_map(fn (array $variant): string => (string) $variant['title'], (array) ($card['variants'] ?? []))),
                'facts' => (array) ($card['facts'] ?? []),
                'uses' => (array) ($card['uses'] ?? []),
                'highlights' => (array) ($card['highlights'] ?? []),
                'related' => $related,
                'text' => mb_substr((string) ($card['text'] ?? ''), 0, $maxTextChars),
            ], fn ($value): bool => $value !== null && $value !== '' && $value !== []),
            ['title' => $product->title, 'category' => $category],
        );
    }

    public static function article(CatalogContent $article, int $maxTextChars): self
    {
        // The products the guide points to, by name only: enough to answer "which one do you
        // mean", never enough to invent a spec.
        $products = EnrichmentContentProduct::query()
            ->with('product')
            ->where('content_id', $article->id)
            ->whereHas('product', fn ($q) => $q->whereNull('removed_at'))
            ->orderBy('rank')
            ->get()
            ->map(fn (EnrichmentContentProduct $match): string => (string) $match->product?->title)
            ->filter()
            ->values()
            ->all();

        $text = trim((string) ($article->body ?? $article->excerpt ?? ''));

        return new self(
            self::ARTICLE,
            $article->id,
            $article->shop_id,
            $article->external_id,
            $article->title,
            [
                'title' => $article->title,
                'text' => mb_substr($text, 0, $maxTextChars),
                'products' => $products,
            ],
            ['title' => $article->title],
        );
    }

    /** The column an answer about this subject is saved under. */
    public function column(): string
    {
        return $this->kind === self::ARTICLE ? 'content_id' : 'product_id';
    }

    /** The prompt written for this subject: "answer" for a product, "answer_article" for a guide. */
    public function prompt(string $name): string
    {
        return $this->kind === self::ARTICLE ? $name.'_article' : $name;
    }
}
