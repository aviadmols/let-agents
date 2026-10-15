<?php

namespace App\Modules\Search\Actions;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Search\Models\SearchShare;
use App\Modules\Search\Support\HebrewSearch;
use App\Modules\Search\Support\LoadedIndex;
use App\Modules\Search\Support\SpellingProductsForQuery;
use Illuminate\Support\Str;

/**
 * A shopper's search, handed to their own AI (ChatGPT, Claude, Perplexity…): the words, what they
 * were read as (a price, a colour, a size), the products found with what the store knows of each
 * (its approved details, its sizes and their stock), and the store's policies, kept as one
 * snapshot behind a short link. The shopper's AI reads it and advises; the purchase stays on the
 * store. Code only: no model is asked, and nothing about the shopper is kept.
 *
 * Prices and stock are the store's as of its last sync, and the page says when that was.
 */
final class ShareSearch
{
    /** Products in one snapshot, at most. */
    public const MAX_PRODUCTS = 24;

    private const MAX_DESCRIPTION = 600;

    private const MAX_VARIANTS = 30;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly SpellingProductsForQuery $spelling,
    ) {}

    /** @param list<string> $externalIds the products the shopper saw, best first; none to search again */
    public function handle(StoreConnection $connection, string $query, array $externalIds = []): SearchShare
    {
        $shopId = (string) $connection->shop_id;

        return $this->tenant->run($shopId, function () use ($connection, $shopId, $query, $externalIds): SearchShare {
            $query = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $query)), 0, 200);
            $limit = min(self::MAX_PRODUCTS, (int) Settings::get('search.ai_share_products', $shopId));
            $ids = array_slice(array_values(array_unique(array_filter(array_map('strval', $externalIds), fn (string $id): bool => preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $id) === 1))), 0, $limit);

            if ($ids === [] && $query !== '') {
                $ids = $this->spelling->find($shopId, $query, $limit);
            }
            // Only the store's own products, in the order sent.
            $known = array_flip(CatalogProduct::query()->whereIn('external_id', $ids)->whereNull('removed_at')->pluck('external_id')->map(fn ($id): string => (string) $id)->all());
            $ids = array_values(array_filter($ids, fn (string $id): bool => isset($known[$id])));

            $fingerprint = hash('sha256', $shopId."\n".$query."\n".implode(',', $ids));
            $kept = SearchShare::query()->where('fingerprint', $fingerprint)->where('created_at', '>', now()->subHour())->where('expires_at', '>', now())->latest()->first();

            if ($kept !== null) {
                return $kept;
            }

            return SearchShare::query()->create([
                'token' => Str::lower(Str::random(12)),
                'fingerprint' => $fingerprint,
                'query' => $query,
                'snapshot' => $this->snapshot($connection, $shopId, $query, $ids),
                'expires_at' => now()->addDays((int) Settings::get('search.ai_share_days', $shopId)),
            ]);
        });
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, mixed>
     */
    private function snapshot(StoreConnection $connection, string $shopId, string $query, array $ids): array
    {
        $products = CatalogProduct::query()->whereIn('external_id', $ids)->whereNull('removed_at')->get()->keyBy('external_id');
        $ordered = array_values(array_filter(array_map(fn (string $id): ?CatalogProduct => $products[$id] ?? null, $ids)));
        $details = $this->details(array_map(fn (CatalogProduct $p): string => (string) $p->id, $ordered));
        [, $min, $max] = HebrewSearch::priceRange($query);
        $index = $query === '' ? null : LoadedIndex::for($shopId);

        return [
            'store' => ['name' => (string) ($connection->shop?->name ?? ''), 'url' => (string) $connection->site_url, 'locale' => (string) ($connection->shop?->content_locale ?? 'he')],
            'query' => $query,
            'read' => [
                'min_price' => $min,
                'max_price' => $max,
                'filters' => $index === null ? [] : array_map(fn (array $n): array => ['name' => $n['title'], 'value' => $n['label']], HebrewSearch::facets($query, (array) ($index['facets'] ?? []))),
            ],
            'products' => array_map(fn (CatalogProduct $p): array => $this->product($p, $details[(string) $p->id] ?? []), $ordered),
            'policies' => $this->policies(),
            'as_of' => collect($ordered)->max(fn (CatalogProduct $p) => $p->synced_at)?->toIso8601String() ?? now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, list<string>>  $details
     * @return array<string, mixed>
     */
    private function product(CatalogProduct $product, array $details): array
    {
        $payload = (array) $product->payload;
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) (($payload['short_description'] ?? '') ?: ($payload['description'] ?? '')))));
        $variants = [];

        foreach (array_slice((array) ($payload['variations'] ?? []), 0, self::MAX_VARIANTS) as $variant) {
            if (! is_array($variant) || trim((string) ($variant['title'] ?? '')) === '') {
                continue;
            }
            $variants[] = [
                'title' => (string) $variant['title'],
                'price' => is_numeric($variant['price']['price'] ?? null) ? (float) $variant['price']['price'] : null,
                'in_stock' => (bool) ($variant['stock']['in_stock'] ?? false),
            ];
        }

        return array_filter([
            'title' => (string) $product->title,
            'url' => $product->url,
            'image' => $product->image_url,
            'brand' => $product->brand,
            'sku' => $product->sku,
            'price' => $product->price === null ? null : (float) $product->price,
            'regular_price' => $product->on_sale && $product->regular_price !== null ? (float) $product->regular_price : null,
            'currency' => $product->currency ?: 'ILS',
            'in_stock' => (bool) $product->in_stock,
            'details' => $details,
            'variants' => $variants,
            'description' => $text === '' ? null : Str::limit($text, self::MAX_DESCRIPTION),
        ], fn ($value): bool => $value !== null && $value !== [] && $value !== '');
    }

    /**
     * What the store knows of each product and has approved: its kind, its choices (metal, stone,
     * colour), its measures and its flags, as labels a person reads.
     *
     * @param  list<string>  $productIds
     * @return array<string, array<string, list<string>>> by product id, label => values
     */
    private function details(array $productIds): array
    {
        $out = [];

        EnrichmentFact::query()->with('vocabulary')->whereIn('product_id', $productIds)->where('status', FactStatus::Approved)
            ->whereIn('kind', [FactKind::Type, FactKind::Choice, FactKind::Spec, FactKind::Flag])
            ->orderBy('kind')->orderBy('key')->orderBy('value_text')->get()
            ->each(function (EnrichmentFact $fact) use (&$out): void {
                $label = $fact->keyLabel();
                $value = $fact->kind === FactKind::Flag ? '✓' : $fact->valueLabel();
                if ($label !== '' && $value !== '' && ! in_array($value, $out[(string) $fact->product_id][$label] ?? [], true)) {
                    $out[(string) $fact->product_id][$label][] = $value;
                }
            });

        return $out;
    }

    /**
     * The store's promises (returns, delivery, warranty, payments) as its own pages state them,
     * and those pages: the shopper's AI can read them before advising.
     *
     * @return array{promises: list<array{name: string, detail: string}>, pages: list<array{title: string, url: string}>}
     */
    private function policies(): array
    {
        $promises = EnrichmentFact::query()->with('vocabulary')->where('kind', FactKind::Promise)->whereNotNull('content_id')
            ->where('status', FactStatus::Approved)->orderBy('key')->get()
            ->map(fn (EnrichmentFact $f): array => ['name' => $f->keyLabel(), 'detail' => trim((string) ($f->quote ?: $f->value_text))])
            ->filter(fn (array $p): bool => $p['detail'] !== '')->unique('name')->values()->all();

        $pages = CatalogContent::query()->active()->where('type', CatalogContent::PAGE)->orderBy('external_id')->get()
            ->filter(fn (CatalogContent $page): bool => $page->isPolicy() && is_string($page->url) && $page->url !== '')
            ->take(8)->map(fn (CatalogContent $page): array => ['title' => (string) $page->title, 'url' => (string) $page->url])->values()->all();

        return ['promises' => $promises, 'pages' => $pages];
    }
}
