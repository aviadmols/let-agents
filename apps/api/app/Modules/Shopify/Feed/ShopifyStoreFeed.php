<?php

namespace App\Modules\Shopify\Feed;

use App\Modules\Connections\Contracts\PlatformStoreFeed;
use App\Modules\Connections\Contracts\StoreFeedUnavailable;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Shopify\Models\ShopifyInstall;
use App\Modules\Shopify\Support\ShopifyApi;
use Generator;

/**
 * A Shopify store's catalog in the same feed shape the WooCommerce plugin serves, read from the
 * Admin GraphQL API: collections as categories, products published to the Online Store, and
 * published pages and blog articles as content. Nothing downstream knows which platform it was.
 *
 * Pages are read by cursor in Shopify's ID order. Shopify refuses a query whose estimated cost
 * is above 1,000 points, and a product with its variants and collections costs about 60, so a
 * product page holds 15 products, not 50.
 */
final class ShopifyStoreFeed implements PlatformStoreFeed
{
    private const PRODUCTS_PER_PAGE = 15;

    private const PER_PAGE = 50;

    private const COLLECTIONS_PER_PRODUCT = 20;

    private const VARIANTS_PER_PRODUCT = 25;

    /** A runaway cursor stops here, far above any catalog cap. */
    private const MAX_PAGES = 5000;

    private const SHOP = <<<'GRAPHQL'
        query Shop { shop { currencyCode primaryDomain { url } } }
        GRAPHQL;

    private const COLLECTIONS = <<<'GRAPHQL'
        query Collections($first: Int!, $after: String) {
          collections(first: $first, after: $after, sortKey: ID, query: "published_status:published") {
            nodes { id title handle description updatedAt image { url } productsCount { count } }
            pageInfo { hasNextPage endCursor }
          }
        }
        GRAPHQL;

    // "published_status:published" is publication to the Online Store; onlineStoreUrl is checked
    // again on every product, because that is what Shopify itself documents as "on the store".
    private const PRODUCTS = <<<'GRAPHQL'
        query Products($first: Int!, $after: String, $collections: Int!, $variants: Int!) {
          products(first: $first, after: $after, sortKey: ID, query: "status:active AND published_status:published") {
            nodes {
              id title handle status onlineStoreUrl descriptionHtml vendor productType tags
              createdAt updatedAt hasOnlyDefaultVariant tracksInventory totalInventory
              variantsCount { count }
              featuredMedia { preview { image { url altText } } }
              priceRangeV2 { minVariantPrice { amount } maxVariantPrice { amount } }
              options { name optionValues { name } }
              collections(first: $collections) { nodes { id title handle } }
              variants(first: $variants) { nodes { id title sku price compareAtPrice availableForSale } }
            }
            pageInfo { hasNextPage endCursor }
          }
        }
        GRAPHQL;

    private const PAGES = <<<'GRAPHQL'
        query Pages($first: Int!, $after: String) {
          pages(first: $first, after: $after, sortKey: ID, query: "published_status:published") {
            nodes { id title handle body bodySummary isPublished createdAt updatedAt }
            pageInfo { hasNextPage endCursor }
          }
        }
        GRAPHQL;

    private const ARTICLES = <<<'GRAPHQL'
        query Articles($first: Int!, $after: String) {
          articles(first: $first, after: $after, sortKey: ID, query: "published_status:published") {
            nodes { id title handle body summary isPublished tags createdAt updatedAt image { url } blog { title handle } }
            pageInfo { hasNextPage endCursor }
          }
        }
        GRAPHQL;

    public function __construct(private readonly ShopifyApi $api) {}

    public function platform(): string
    {
        return 'shopify';
    }

    public function categories(StoreConnection $connection): array
    {
        $install = $this->install($connection);
        $base = $this->shop($install)['base'];
        $records = [];

        foreach ($this->pages($install, self::COLLECTIONS, 'collections', ['first' => self::PER_PAGE]) as $nodes) {
            foreach ($nodes as $node) {
                $name = self::line($node['title'] ?? '');

                $records[] = [
                    'external_id' => self::id($node['id']),
                    'name' => $name,
                    'slug' => (string) ($node['handle'] ?? ''),
                    // Collections are flat in Shopify: no parent, and the path is the name alone.
                    'parent_id' => null,
                    'path' => [$name],
                    'description' => (string) ($node['description'] ?? ''),
                    'product_count' => (int) data_get($node, 'productsCount.count', 0),
                    'url' => $base.'/collections/'.$node['handle'],
                    'image' => (string) data_get($node, 'image.url', ''),
                    'updated_at' => $node['updatedAt'] ?? null,
                ];
            }
        }

        return $records;
    }

    public function products(StoreConnection $connection): Generator
    {
        $install = $this->install($connection);
        $shop = $this->shop($install);
        $variables = ['first' => self::PRODUCTS_PER_PAGE, 'collections' => self::COLLECTIONS_PER_PRODUCT, 'variants' => self::VARIANTS_PER_PRODUCT];

        foreach ($this->pages($install, self::PRODUCTS, 'products', $variables) as $nodes) {
            $records = [];

            foreach ($nodes as $node) {
                // Active but not on the Online Store (another sales channel only, or unlisted):
                // a visitor cannot reach it, so it is not part of the catalog.
                if (($node['status'] ?? null) !== 'ACTIVE' || ! is_string($node['onlineStoreUrl'] ?? null)) {
                    continue;
                }

                $records[] = $this->product($node, $shop);
            }

            if ($records !== []) {
                yield $records;
            }
        }
    }

    public function content(StoreConnection $connection, string $type): Generator
    {
        [$query, $field] = match ($type) {
            'page' => [self::PAGES, 'pages'],
            'post' => [self::ARTICLES, 'articles'],
            default => [null, null],
        };

        if ($query === null) {
            return;
        }

        $install = $this->install($connection);
        $base = $this->shop($install)['base'];

        foreach ($this->pages($install, $query, $field, ['first' => self::PER_PAGE]) as $nodes) {
            $records = [];

            foreach ($nodes as $node) {
                if (($node['isPublished'] ?? false) !== true) {
                    continue;
                }

                $records[] = $type === 'page' ? $this->page($node, $base) : $this->article($node, $base);
            }

            if ($records !== []) {
                yield $records;
            }
        }
    }

    /** Online store pages and blog articles: what a Shopify store publishes besides products. */
    public function contentTypes(StoreConnection $connection): array
    {
        return ['page', 'post'];
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array{currency: string, base: string}  $shop
     * @return array<string, mixed>
     */
    private function product(array $node, array $shop): array
    {
        $variants = array_values(array_filter((array) data_get($node, 'variants.nodes', []), 'is_array'));
        $variantsCount = (int) data_get($node, 'variantsCount.count', count($variants));
        $truncated = $variantsCount > count($variants);

        // The cheapest variant sets the price and its compare-at price the regular one, so a sale
        // shown is a sale on the price shown. The product's price range covers variants past the
        // ones read here.
        $priced = array_values(array_filter($variants, fn (array $v): bool => is_numeric($v['price'] ?? null)));
        usort($priced, fn (array $a, array $b): int => (float) $a['price'] <=> (float) $b['price']);
        $cheapest = $priced[0] ?? null;

        $min = data_get($node, 'priceRangeV2.minVariantPrice.amount', $cheapest['price'] ?? null);
        $max = data_get($node, 'priceRangeV2.maxVariantPrice.amount');
        $price = is_numeric($min) ? self::decimal($min) : '';
        $compareAt = $cheapest !== null && is_numeric($cheapest['compareAtPrice'] ?? null) ? (float) $cheapest['compareAtPrice'] : 0.0;
        $onSale = $price !== '' && $compareAt > (float) $price;

        $available = array_filter($variants, fn (array $v): bool => ($v['availableForSale'] ?? false) === true) !== [];
        // Variants past the ones read may be the ones in stock.
        if (! $available && $truncated) {
            $available = ! ($node['tracksInventory'] ?? true) || (int) ($node['totalInventory'] ?? 0) > 0;
        }

        $vendor = self::line($node['vendor'] ?? '');
        $image = data_get($node, 'featuredMedia.preview.image');
        $simple = ($node['hasOnlyDefaultVariant'] ?? true) === true;

        $record = [
            'external_id' => self::id($node['id']),
            'platform' => 'shopify',
            'type' => $simple ? 'simple' : 'variable',
            // Only Online Store products are read, so every one is published, in WooCommerce's word.
            'status' => 'publish',
            'title' => self::line($node['title'] ?? ''),
            'slug' => (string) ($node['handle'] ?? ''),
            // The storefront builds product links and cart requests from the handle.
            'handle' => (string) ($node['handle'] ?? ''),
            'url' => (string) ($node['onlineStoreUrl'] ?? $shop['base'].'/products/'.($node['handle'] ?? '')),
            'sku' => (string) ($variants[0]['sku'] ?? ''),
            'short_description' => '',
            'description' => self::text($node['descriptionHtml'] ?? ''),
            'images' => is_array($image) && is_string($image['url'] ?? null)
                ? [['url' => $image['url'], 'alt' => self::line($image['altText'] ?? '')]]
                : [],
            'categories' => array_values(array_map(fn (array $c): array => [
                'id' => self::id($c['id']),
                'name' => self::line($c['title'] ?? ''),
                'slug' => (string) ($c['handle'] ?? ''),
                'path' => [self::line($c['title'] ?? '')],
            ], array_filter((array) data_get($node, 'collections.nodes', []), fn ($c): bool => is_array($c) && isset($c['id'])))),
            'tags' => array_values(array_filter(array_map(fn ($t): string => self::line((string) $t), (array) ($node['tags'] ?? [])), fn (string $t): bool => $t !== '')),
            'brands' => $vendor !== '' ? [$vendor] : [],
            'vendor' => $vendor,
            'product_type' => self::line($node['productType'] ?? ''),
            'attributes' => $simple ? [] : $this->attributes((array) ($node['options'] ?? [])),
            'meta' => [],
            'relations' => [],
            'price' => [
                'currency' => $shop['currency'],
                'price' => $price,
                'regular_price' => $onSale ? self::decimal($compareAt) : $price,
                'on_sale' => $onSale,
                'min_price' => $price,
                'max_price' => is_numeric($max) ? self::decimal($max) : $price,
            ],
            'stock' => [
                'in_stock' => $available,
                'managed' => (bool) ($node['tracksInventory'] ?? false),
                'quantity' => ($node['tracksInventory'] ?? false) ? (int) ($node['totalInventory'] ?? 0) : null,
            ],
            // Published, active and priced: Shopify lets it into a cart (stock aside, as in WooCommerce).
            'purchasable' => $price !== '',
            'variations' => $simple ? [] : array_map(fn (array $v): array => [
                'external_id' => self::id($v['id']),
                'title' => self::line($v['title'] ?? ''),
                'sku' => (string) ($v['sku'] ?? ''),
                'price' => [
                    'price' => is_numeric($v['price'] ?? null) ? self::decimal($v['price']) : '',
                    'regular_price' => is_numeric($v['compareAtPrice'] ?? null) && (float) $v['compareAtPrice'] > (float) ($v['price'] ?? 0) ? self::decimal($v['compareAtPrice']) : (is_numeric($v['price'] ?? null) ? self::decimal($v['price']) : ''),
                    'on_sale' => is_numeric($v['compareAtPrice'] ?? null) && (float) $v['compareAtPrice'] > (float) ($v['price'] ?? 0),
                ],
                'stock' => ['in_stock' => ($v['availableForSale'] ?? false) === true],
                'purchasable' => ($v['availableForSale'] ?? false) === true,
            ], $variants),
            'created_at' => $node['createdAt'] ?? null,
            'updated_at' => $node['updatedAt'] ?? null,
        ];

        if (! $simple && $truncated) {
            $record['variations_truncated'] = true;
        }

        return $record;
    }

    /**
     * Product options in the attribute shape the plugin sends: every option is used for variants.
     *
     * @param  array<int, mixed>  $options
     * @return list<array<string, mixed>>
     */
    private function attributes(array $options): array
    {
        $attributes = [];

        foreach ($options as $option) {
            if (! is_array($option)) {
                continue;
            }

            $name = self::line($option['name'] ?? '');
            $values = array_values(array_filter(array_map(fn ($v): string => self::line(is_array($v) ? ($v['name'] ?? '') : ''), (array) ($option['optionValues'] ?? [])), fn (string $v): bool => $v !== ''));

            if ($name !== '' && $values !== []) {
                $attributes[] = ['name' => $name, 'key' => $name, 'taxonomy' => false, 'values' => $values, 'used_for_variations' => true, 'visible' => true];
            }
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function page(array $node, string $base): array
    {
        $text = self::text($node['body'] ?? '');
        $summary = self::line($node['bodySummary'] ?? '');

        return [
            'external_id' => self::id($node['id']),
            'type' => 'page',
            'title' => self::line($node['title'] ?? ''),
            'slug' => (string) ($node['handle'] ?? ''),
            'url' => $base.'/pages/'.$node['handle'],
            'excerpt' => $summary !== '' ? $summary : self::excerpt($text),
            'text' => $text,
            'image' => '',
            'terms' => [],
            'product_ids' => [],
            'created_at' => $node['createdAt'] ?? null,
            'updated_at' => $node['updatedAt'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function article(array $node, string $base): array
    {
        $text = self::text($node['body'] ?? '');
        $summary = self::text($node['summary'] ?? '');
        $tags = array_values(array_filter(array_map(fn ($t): string => self::line((string) $t), (array) ($node['tags'] ?? [])), fn (string $t): bool => $t !== ''));
        $blog = self::line(data_get($node, 'blog.title', ''));

        return [
            'external_id' => self::id($node['id']),
            'type' => 'post',
            'title' => self::line($node['title'] ?? ''),
            'slug' => (string) ($node['handle'] ?? ''),
            'url' => $base.'/blogs/'.data_get($node, 'blog.handle', '').'/'.$node['handle'],
            'excerpt' => $summary !== '' ? $summary : self::excerpt($text),
            'text' => $text,
            'image' => (string) data_get($node, 'image.url', ''),
            // The blog an article sits in plays the part of a WordPress category.
            'terms' => array_filter(['tags' => $tags, 'blog' => $blog !== '' ? [$blog] : []]),
            'product_ids' => [],
            'created_at' => $node['createdAt'] ?? null,
            'updated_at' => $node['updatedAt'] ?? null,
        ];
    }

    private function install(StoreConnection $connection): ShopifyInstall
    {
        $install = ShopifyInstall::query()->where('shop_id', $connection->shop_id)->first();

        if ($install === null || ! $install->installed()) {
            throw new StoreFeedUnavailable('not_installed');
        }

        return $install;
    }

    /**
     * The shop's currency and the address its storefront answers on (its own domain when it has
     * one, otherwise the myshopify one).
     *
     * @return array{currency: string, base: string}
     */
    private function shop(ShopifyInstall $install): array
    {
        $shop = (array) ($this->api->query($install, self::SHOP)['shop'] ?? []);
        $url = data_get($shop, 'primaryDomain.url');

        return [
            'currency' => (string) ($shop['currencyCode'] ?? ''),
            'base' => is_string($url) && $url !== '' ? rtrim($url, '/') : 'https://'.$install->shop_domain,
        ];
    }

    /**
     * Every node of one top-level connection, a page at a time.
     *
     * @param  array<string, mixed>  $variables
     * @return Generator<int, list<array<string, mixed>>>
     */
    private function pages(ShopifyInstall $install, string $query, string $field, array $variables): Generator
    {
        $after = null;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $data = $this->api->query($install, $query, $variables + ['after' => $after]);
            $connection = $data[$field] ?? null;

            if (! is_array($connection)) {
                throw new StoreFeedUnavailable('unexpected_response');
            }

            $nodes = array_values(array_filter((array) ($connection['nodes'] ?? []), fn ($n): bool => is_array($n) && isset($n['id'])));

            if ($nodes !== []) {
                yield $nodes;
            }

            $cursor = data_get($connection, 'pageInfo.endCursor');

            if (data_get($connection, 'pageInfo.hasNextPage') !== true || ! is_string($cursor) || $cursor === $after) {
                return;
            }

            $after = $cursor;
        }
    }

    /** "gid://shopify/Product/8123" is "8123": the plain number is what the storefront knows. */
    private static function id(mixed $gid): string
    {
        $gid = (string) $gid;

        return str_contains($gid, '/') ? substr($gid, (int) strrpos($gid, '/') + 1) : $gid;
    }

    /** Prices as the plugin sends them: "299", "39.9". */
    private static function decimal(mixed $value): string
    {
        $value = number_format((float) $value, 2, '.', '');

        return rtrim(rtrim($value, '0'), '.');
    }

    /** Titles and labels: one line, decoded. */
    private static function line(mixed $value): string
    {
        $value = html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return (string) preg_replace('/^\s+|\s+$/u', '', (string) preg_replace('/\s+/u', ' ', $value));
    }

    /**
     * Rich text as clean text, the way the plugin sends it: paragraph and line breaks survive as
     * newlines; markup, scripts and entities do not.
     */
    private static function text(mixed $html): string
    {
        $text = (string) $html;

        if ($text === '') {
            return '';
        }

        $text = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $text);
        $text = (string) preg_replace('#<br\b[^>]*>|</(p|div|li|h[1-6]|tr|blockquote|summary|details|section|article|header|footer|figcaption|dt|dd|ul|ol|table)\s*>#i', "\n", $text);
        $text = (string) preg_replace('#</(td|th)\s*>#i', ' ', $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $text);
        $text = (string) preg_replace("/ *\n */u", "\n", $text);
        $text = (string) preg_replace("/\n{3,}/u", "\n\n", $text);

        return (string) preg_replace('/^\s+|\s+$/u', '', $text);
    }

    private static function excerpt(string $text): string
    {
        return mb_strlen($text) > 300 ? (string) preg_replace('/\s+$/u', '', mb_substr($text, 0, 300)).'…' : $text;
    }
}
