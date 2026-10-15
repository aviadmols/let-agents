<?php

namespace App\Modules\Search\Support;

use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentFact;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The store as a shopper's own AI reads it: the store itself, its products with the details the
 * store approved and their options, and its policies. One shape for the shared search page
 * (ShareSearch) and the AI connector (McpController). Code only, inside the shop's tenant.
 * Prices and stock are the store's as of its last sync.
 */
final class AgentCatalog
{
    private const MAX_DESCRIPTION = 600;

    private const MAX_VARIANTS = 30;

    /** A public link on the platform's own address (agents.lets.co.il), never the address a request happened to come on. */
    public static function publicUrl(string $path): string
    {
        $domain = trim((string) config('upsell.shop_domain'));
        $root = $domain !== '' ? 'https://'.$domain : rtrim((string) config('app.url'), '/');

        return $root.'/'.ltrim($path, '/');
    }

    /**
     * What an AI is told before anything else, on every page it reads: where every product is,
     * how to buy, and to end every answer with the link that buys.
     *
     * @param  array<string, mixed>  $store
     * @return list<string>
     */
    public static function instructions(array $store, string $time): array
    {
        $locale = in_array($store['locale'] ?? 'he', ['he', 'en'], true) ? $store['locale'] : 'he';
        $t = fn (string $key, array $params = []): string => (string) __('search::share.'.$key, $params, $locale);
        $catalog = $store['catalog'] ?? null;
        $shopify = ($store['platform'] ?? '') === 'shopify';

        return array_values(array_filter([
            $catalog ? $t('guide_search', ['store' => $store['name']]).' '.$catalog.'?q=… ('.$t('guide_example').' '.$catalog.'?q='.$t('guide_example_query').'; '.$t('guide_pages').' &page=2; JSON: &format=json)' : null,
            $t('guide_products'),
            $shopify ? $t('guide_buy_shopify') : $t('guide_buy_woo'),
            $shopify ? $t('guide_combined').' '.rtrim((string) $store['url'], '/').'/cart/'.$t('guide_cart_id').':1,'.$t('guide_cart_id').':2?attributes[la_source]=ai' : null,
            $t('guide_end'),
            $t('guide_rules', ['time' => $time]),
        ]));
    }

    /**
     * The page as an AI reads it most easily: one JSON object, the instructions first, then each
     * product with only what helps to choose and to buy.
     *
     * @param  array<string, mixed>  $store
     * @param  list<array<string, mixed>>  $products
     * @param  array<string, mixed>  $more  query, read, total, page, pages, next_page, policies
     * @return array<string, mixed>
     */
    public static function forAi(array $store, array $products, string $asOf, array $more = []): array
    {
        $time = Carbon::parse($asOf)->timezone('Asia/Jerusalem')->format('d.m.Y H:i');

        return array_filter([
            'for_ai' => self::instructions($store, $time),
            'store' => array_intersect_key($store, array_flip(['name', 'url', 'platform', 'catalog'])),
            'prices_as_of' => $asOf,
        ] + $more + [
            'products' => array_map(fn (array $p): array => array_filter([
                'id' => $p['id'] ?? null,
                'title' => $p['title'] ?? null,
                'price' => $p['price'] ?? null,
                'was' => $p['regular_price'] ?? null,
                'currency' => $p['currency'] ?? null,
                'in_stock' => $p['in_stock'] ?? null,
                'details' => array_map(fn (array $values): string => implode(', ', $values), $p['details'] ?? []) ?: null,
                'buy_url' => $p['buy_url'] ?? null,
                'cart_id' => $p['cart_id'] ?? null,
                'options' => array_map(fn (array $v): array => array_filter([
                    'title' => $v['title'] ?? null, 'cart_id' => $v['id'] ?? null, 'price' => $v['price'] ?? null, 'in_stock' => $v['in_stock'] ?? null, 'buy_url' => $v['buy_url'] ?? null,
                ], fn ($value): bool => $value !== null), $p['variants'] ?? []) ?: null,
                'page_url' => $p['url'] ?? null,
                'image' => $p['image'] ?? null,
            ], fn ($value): bool => $value !== null && $value !== ''), $products),
        ], fn ($value): bool => $value !== null && $value !== [] && $value !== '');
    }

    /** @return array{name: string, url: string, locale: string, platform: string, catalog: string|null} */
    public static function store(StoreConnection $connection): array
    {
        return [
            'name' => (string) ($connection->shop?->name ?? ''),
            'url' => (string) $connection->site_url,
            'locale' => (string) ($connection->shop?->content_locale ?? 'he'),
            'platform' => (string) $connection->platform,
            // The store's page for an AI: every product, searchable, with the instructions on top.
            'catalog' => $connection->site_key ? self::publicUrl('c/'.$connection->site_key) : null,
        ];
    }

    /**
     * The products, in the order asked, each as an AI reads it; ids the store does not have are left out.
     *
     * @param  list<string>  $externalIds
     * @return list<array<string, mixed>>
     */
    public static function products(array $externalIds, ?array $store = null): array
    {
        $products = CatalogProduct::query()->whereIn('external_id', $externalIds)->whereNull('removed_at')->get()->keyBy('external_id');
        $ordered = array_values(array_filter(array_map(fn (string $id): ?CatalogProduct => $products[$id] ?? null, $externalIds)));
        $details = self::details(array_map(fn (CatalogProduct $p): string => (string) $p->id, $ordered));

        return array_map(fn (CatalogProduct $p): array => self::product($p, $details[(string) $p->id] ?? [], $store), $ordered);
    }

    /** When the products were last read from the store, as ISO 8601. @param list<string> $externalIds */
    public static function asOf(array $externalIds): string
    {
        $at = CatalogProduct::query()->whereIn('external_id', $externalIds)->max('synced_at');

        return $at === null ? now()->toIso8601String() : Carbon::parse($at)->toIso8601String();
    }

    /**
     * @param  array<string, list<string>>  $details
     * @return array<string, mixed>
     */
    private static function product(CatalogProduct $product, array $details, ?array $store = null): array
    {
        $payload = (array) $product->payload;
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) (($payload['short_description'] ?? '') ?: ($payload['description'] ?? '')))));
        $variants = [];

        foreach (array_slice((array) ($payload['variations'] ?? []), 0, self::MAX_VARIANTS) as $variant) {
            if (! is_array($variant) || trim((string) ($variant['title'] ?? '')) === '') {
                continue;
            }
            $variants[] = array_filter([
                'id' => (string) ($variant['external_id'] ?? ''),
                'title' => (string) $variant['title'],
                'price' => is_numeric($variant['price']['price'] ?? null) ? (float) $variant['price']['price'] : null,
                'in_stock' => (bool) ($variant['stock']['in_stock'] ?? false),
                'buy_url' => $store === null || ! ($variant['stock']['in_stock'] ?? false) ? null : CartLink::buyUrl($store, (string) $product->external_id, (string) ($variant['external_id'] ?? '')),
            ], fn ($value): bool => $value !== null && $value !== '');
        }

        return array_filter([
            'id' => (string) $product->external_id,
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
            // A product without options is bought from here; one with options, from the option chosen.
            'buy_url' => $store === null || $variants !== [] || ! $product->in_stock ? null : CartLink::buyUrl($store, (string) $product->external_id, (string) ($payload['variant_id'] ?? '')),
            'cart_id' => $variants === [] && ($payload['variant_id'] ?? '') !== '' ? (string) $payload['variant_id'] : null,
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
    private static function details(array $productIds): array
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
     * and those pages.
     *
     * @return array{promises: list<array{name: string, detail: string}>, pages: list<array{title: string, url: string}>}
     */
    public static function policies(): array
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

    /**
     * What the words were read as: a price range and the store's filter values they name.
     *
     * @return array{min_price: float|null, max_price: float|null, filters: list<array{name: string, value: string}>}
     */
    public static function read(string $shopId, string $query): array
    {
        [, $min, $max] = HebrewSearch::priceRange($query);
        $index = trim($query) === '' ? null : LoadedIndex::for($shopId);

        return [
            'min_price' => $min,
            'max_price' => $max,
            'filters' => $index === null ? [] : array_map(fn (array $n): array => ['name' => $n['title'], 'value' => $n['label']], HebrewSearch::facets($query, (array) ($index['facets'] ?? []))),
        ];
    }
}
