<?php

namespace App\Modules\Shopify\Feed;

use App\Modules\Connections\Contracts\PlatformStoreFeed;
use App\Modules\Connections\Contracts\ReadsChangedProducts;
use App\Modules\Connections\Contracts\StoreFeedUnavailable;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Shopify\Models\ShopifyInstall;
use App\Modules\Shopify\Support\ShopifyApi;
use Carbon\CarbonInterface;
use Generator;
use Illuminate\Support\Facades\Http;

/**
 * A Shopify store's catalog in the same feed shape the WooCommerce plugin serves, read from the
 * Admin GraphQL API: collections as categories, products published to the Online Store, and
 * published pages and blog articles as content. Nothing downstream knows which platform it was.
 *
 * Pages are read by cursor in Shopify's ID order, newest first: a read cut short still has the new
 * products, which matter most. Shopify refuses a query whose estimated cost
 * is above 1,000 points, and a product with its variants and collections costs about 60, so a
 * product page holds 15 products, not 50.
 */
final class ShopifyStoreFeed implements PlatformStoreFeed, ReadsChangedProducts
{
    private const PRODUCTS_PER_PAGE = 15;

    private const PER_PAGE = 50;

    private const COLLECTIONS_PER_PRODUCT = 20;

    private const VARIANTS_PER_PRODUCT = 25;

    /**
     * The details are read apart from the page (see DETAILS), a few products a request: their
     * variants carry a weight and a picture each, which costs about 150 points a product.
     */
    private const DETAILS_PER_REQUEST = 5;

    private const METAFIELDS_PER_PRODUCT = 25;

    /** A custom field's value is a label or a spec, not a document: longer ones are cut, as in the plugin. */
    private const MAX_META_VALUE_CHARS = 500;

    /** Shopify's measurement units, in the short form the plugin sends. */
    private const UNITS = [
        'GRAMS' => 'g', 'KILOGRAMS' => 'kg', 'OUNCES' => 'oz', 'POUNDS' => 'lbs',
        'MILLIMETERS' => 'mm', 'CENTIMETERS' => 'cm', 'METERS' => 'm', 'INCHES' => 'in', 'FEET' => 'ft', 'YARDS' => 'yd',
        'MILLILITERS' => 'ml', 'CENTILITERS' => 'cl', 'LITERS' => 'l', 'CUBIC_METERS' => 'm3', 'FLUID_OUNCES' => 'fl oz', 'GALLONS' => 'gal',
    ];

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
          products(first: $first, after: $after, sortKey: ID, reverse: true, query: "status:active AND published_status:published") {
            nodes {
              id title handle status onlineStoreUrl descriptionHtml vendor productType tags
              createdAt updatedAt hasOnlyDefaultVariant tracksInventory totalInventory
              variantsCount { count }
              featuredMedia { preview { image { url altText } } }
              media(first: 4) { nodes { preview { image { url altText } } } }
              priceRangeV2 { minVariantPrice { amount } maxVariantPrice { amount } }
              options { name optionValues { name } }
              collections(first: $collections) { nodes { id title handle } }
              variants(first: $variants) { nodes { id title sku price compareAtPrice availableForSale } }
            }
            pageInfo { hasNextPage endCursor }
          }
        }
        GRAPHQL;

    // What the products page leaves out: the store's custom fields, and each variant's options,
    // barcode, picture and weight. A query of its own, so a store whose answer to it fails (a
    // field the app may not read, a cost over the limit) still has its catalog read, without them.
    private const DETAILS = <<<'GRAPHQL'
        query ProductDetails($ids: [ID!]!, $metafields: Int!, $variants: Int!) {
          nodes(ids: $ids) {
            ... on Product {
              id
              metafields(first: $metafields) { nodes { namespace key type value } }
              variants(first: $variants) {
                nodes { id barcode selectedOptions { name value } image { url } inventoryItem { measurement { weight { unit value } } } }
              }
            }
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

    /** The store's policies (refunds, returns, shipping, terms, privacy): pages in Shopify's own place, needs read_legal_policies. */
    private const POLICIES = <<<'GRAPHQL'
        query Policies { shop { shopPolicies { id type title body url updatedAt } } }
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

        // A store behind a password (a development store, a store before launch): Shopify gives no
        // product an Online Store address, though the query already asked for published ones only.
        // Decided on the first page: none of its active products has an address.
        $locked = null;

        foreach ($this->pages($install, self::PRODUCTS, 'products', $variables) as $nodes) {
            $active = array_values(array_filter($nodes, fn (array $node): bool => ($node['status'] ?? null) === 'ACTIVE'));
            $locked ??= $active !== [] && array_filter($active, fn (array $node): bool => is_string($node['onlineStoreUrl'] ?? null)) === [];
            // Active but not on the Online Store (another sales channel only, or unlisted):
            // a visitor cannot reach it, so it is not part of the catalog. Behind a password every
            // published product counts, its link made from its handle.
            $nodes = $locked ? $active : array_values(array_filter($active, fn (array $node): bool => is_string($node['onlineStoreUrl'] ?? null)));
            $details = $this->details($install, array_column($nodes, 'id'));
            $records = [];

            foreach ($nodes as $node) {
                $records[] = $this->product($node, $shop, $details[$node['id']] ?? null);
            }

            if ($records !== []) {
                yield $records;
            }
        }
    }

    /**
     * The products changed since a moment, any status, newest change last. One still on the Online
     * Store is a record; one archived or set to draft is gone. One active without an Online Store
     * address is left to the nightly read, which knows whether the whole store is behind a password.
     */
    public function changedProducts(StoreConnection $connection, CarbonInterface $since): ?Generator
    {
        return $this->changed($connection, $since);
    }

    /** @return Generator<int, array{records: list<array<string, mixed>>, gone: list<string>}> */
    private function changed(StoreConnection $connection, CarbonInterface $since): Generator
    {
        $install = $this->install($connection);
        $shop = $this->shop($install);
        // Publishing a product to the store does not always move its update time: published and
        // created since are asked too.
        $at = "'".$since->copy()->utc()->format('Y-m-d\TH:i:s\Z')."'";
        $variables = [
            'first' => self::PRODUCTS_PER_PAGE, 'collections' => self::COLLECTIONS_PER_PRODUCT, 'variants' => self::VARIANTS_PER_PRODUCT,
            'filter' => "updated_at:>{$at} OR published_at:>{$at} OR created_at:>{$at}",
        ];

        foreach ($this->pages($install, self::changedQuery(), 'products', $variables) as $nodes) {
            $on = array_values(array_filter($nodes, fn (array $node): bool => ($node['status'] ?? null) === 'ACTIVE' && is_string($node['onlineStoreUrl'] ?? null)));
            $gone = array_values(array_map(fn (array $node): string => self::id($node['id']), array_filter($nodes, fn (array $node): bool => ($node['status'] ?? null) !== 'ACTIVE')));
            $details = $on === [] ? [] : $this->details($install, array_column($on, 'id'));

            yield [
                'records' => array_map(fn (array $node): array => $this->product($node, $shop, $details[$node['id']] ?? null), $on),
                'gone' => $gone,
            ];
        }
    }

    /** The products query with the same fields, for what changed: any status, by update time. */
    private static function changedQuery(): string
    {
        return str_replace(
            ['query Products($first: Int!, $after: String,', 'sortKey: ID, reverse: true, query: "status:active AND published_status:published"'],
            ['query ChangedProducts($first: Int!, $after: String, $filter: String!,', 'sortKey: UPDATED_AT, query: $filter'],
            self::PRODUCTS,
        );
    }

    /**
     * The store's policies as pages: what a shopper asks about cancelling, returns or shipping is
     * answered from them. Nothing when the store installed the app before it asked to read them.
     *
     * @return list<array<string, mixed>>
     */
    private function policies(ShopifyInstall $install): array
    {
        try {
            $policies = (array) data_get($this->api->query($install, self::POLICIES), 'shop.shopPolicies', []);
        } catch (StoreFeedUnavailable) {
            return [];
        }

        $records = [];

        foreach ($policies as $policy) {
            $text = self::text($policy['body'] ?? '');

            if (! is_array($policy) || $text === '' || ! isset($policy['id'])) {
                continue;
            }

            $records[] = [
                'external_id' => 'policy-'.self::id($policy['id']),
                'type' => 'page',
                'title' => self::line($policy['title'] ?? ''),
                'slug' => strtolower((string) ($policy['type'] ?? '')),
                'url' => (string) ($policy['url'] ?? ''),
                'excerpt' => self::excerpt($text),
                'text' => $text,
                'image' => '',
                'terms' => [],
                'product_ids' => [],
                'created_at' => null,
                'updated_at' => $policy['updatedAt'] ?? null,
            ];
        }

        return $records;
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

        if ($type === 'page' && ($policies = $this->policies($install)) !== []) {
            yield $policies;
        }

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
     * @param  array{meta: array<string, string>, variants: array<string, array<string, mixed>>}|null  $details  null when they could not be read
     * @return array<string, mixed>
     */
    private function product(array $node, array $shop, ?array $details = null): array
    {
        $variants = array_values(array_filter((array) data_get($node, 'variants.nodes', []), 'is_array'));
        $more = fn (array $variant): array => $details['variants'][$variant['id'] ?? ''] ?? [];
        // The variant the product page opens on stands for a product without options.
        $first = $variants === [] ? [] : $more($variants[0]);
        $weighed = array_values(array_filter(array_map($more, $variants), fn (array $v): bool => ($v['weight'] ?? '') !== ''));
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
            'gtin' => (string) ($first['barcode'] ?? ''),
            'short_description' => '',
            'description' => self::text($node['descriptionHtml'] ?? ''),
            'images' => self::images($image, (array) data_get($node, 'media.nodes', [])),
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
            'meta' => $details['meta'] ?? [],
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
            // Shopify weighs variants, not products: the first one weighed stands for the product,
            // as the variant the page opens on stands for its price. No measurements besides weight.
            'dimensions' => [
                'weight' => (string) ($weighed[0]['weight'] ?? ''),
                'length' => '',
                'width' => '',
                'height' => '',
                'weight_unit' => (string) ($weighed[0]['weight_unit'] ?? ''),
                'dimension_unit' => '',
            ],
            'variations' => $simple ? [] : array_map(fn (array $v): array => [
                'external_id' => self::id($v['id']),
                'title' => self::line($v['title'] ?? ''),
                'sku' => (string) ($v['sku'] ?? ''),
                'barcode' => (string) ($more($v)['barcode'] ?? ''),
                // Which value of each option this variant is ("מידה: 7"), in the plugin's shape.
                'attributes' => $more($v)['attributes'] ?? [],
                'image' => (string) ($more($v)['image'] ?? ''),
                'weight' => (string) ($more($v)['weight'] ?? ''),
                'weight_unit' => (string) ($more($v)['weight_unit'] ?? ''),
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
     * The details of a page of products, by product id. A request that fails leaves its products
     * without them: the catalog is read either way, and the next sync tries again.
     *
     * @param  list<string>  $ids  product gids
     * @return array<string, array{meta: array<string, string>, variants: array<string, array<string, mixed>>}>
     */
    private function details(ShopifyInstall $install, array $ids): array
    {
        $details = [];

        foreach (array_chunk($ids, self::DETAILS_PER_REQUEST) as $chunk) {
            try {
                $nodes = (array) ($this->api->query($install, self::DETAILS, [
                    'ids' => $chunk,
                    'metafields' => self::METAFIELDS_PER_PRODUCT,
                    'variants' => self::VARIANTS_PER_PRODUCT,
                ])['nodes'] ?? []);
            } catch (StoreFeedUnavailable) {
                continue;
            }

            foreach ($nodes as $node) {
                if (! is_array($node) || ! isset($node['id'])) {
                    continue;
                }

                $meta = [];
                foreach ((array) data_get($node, 'metafields.nodes', []) as $field) {
                    $key = is_array($field) ? trim((string) ($field['namespace'] ?? '')).'.'.trim((string) ($field['key'] ?? '')) : '';
                    $value = is_array($field) ? self::metafield((string) ($field['type'] ?? ''), $field['value'] ?? null) : '';

                    // An app's own fields are its internals, as "_" keys are in WordPress.
                    if ($value !== '' && $key !== '.' && ! preg_match('/^(\$app|app--)/', $key)) {
                        $meta[$key] = mb_substr($value, 0, self::MAX_META_VALUE_CHARS);
                    }
                }

                $variants = [];
                foreach ((array) data_get($node, 'variants.nodes', []) as $variant) {
                    if (! is_array($variant) || ! isset($variant['id'])) {
                        continue;
                    }

                    $weight = data_get($variant, 'inventoryItem.measurement.weight');
                    $weighed = is_array($weight) && is_numeric($weight['value'] ?? null) && (float) $weight['value'] > 0;

                    $variants[$variant['id']] = [
                        'barcode' => self::line($variant['barcode'] ?? ''),
                        'attributes' => array_values(array_map(fn (array $o): array => [
                            'key' => self::line($o['name'] ?? ''),
                            'name' => self::line($o['name'] ?? ''),
                            'value' => self::line($o['value'] ?? ''),
                        ], array_filter((array) ($variant['selectedOptions'] ?? []), fn ($o): bool => is_array($o)
                            && self::line($o['name'] ?? '') !== '' && ($o['value'] ?? '') !== 'Default Title'))),
                        'image' => (string) data_get($variant, 'image.url', ''),
                        'weight' => $weighed ? self::decimal($weight['value']) : '',
                        'weight_unit' => $weighed ? (self::UNITS[$weight['unit'] ?? ''] ?? strtolower((string) ($weight['unit'] ?? ''))) : '',
                    ];
                }

                $details[$node['id']] = ['meta' => $meta, 'variants' => $variants];
            }
        }

        return $details;
    }

    /**
     * A custom field's value as text a person or a reader of specs can use: "1.6 g" for a weight,
     * "זהב, כסף" for a list. References to other records and raw JSON say nothing on their own.
     */
    private static function metafield(string $type, mixed $value): string
    {
        if (! is_scalar($value) || str_contains($type, 'reference') || $type === 'json') {
            return '';
        }

        $value = (string) $value;

        if (str_starts_with($type, 'list.')) {
            $items = json_decode($value, true);

            return is_array($items) ? implode(', ', array_filter(array_map(fn ($item): string => self::metafield(substr($type, 5), is_array($item) ? json_encode($item) : $item), $items))) : '';
        }

        if (in_array($type, ['weight', 'dimension', 'volume'], true)) {
            $measure = json_decode($value, true);

            return is_array($measure) && is_numeric($measure['value'] ?? null)
                ? self::decimal($measure['value']).' '.(self::UNITS[strtoupper((string) ($measure['unit'] ?? ''))] ?? strtolower((string) ($measure['unit'] ?? '')))
                : '';
        }

        if ($type === 'money') {
            $money = json_decode($value, true);

            return is_array($money) && is_numeric($money['amount'] ?? null) ? self::decimal($money['amount']).' '.($money['currency_code'] ?? '') : '';
        }

        if ($type === 'rating') {
            $rating = json_decode($value, true);

            return is_array($rating) && is_numeric($rating['value'] ?? null) ? $rating['value'].'/'.($rating['scale_max'] ?? '') : '';
        }

        if ($type === 'rich_text_field') {
            $text = [];
            $tree = (array) json_decode($value, true);
            array_walk_recursive($tree, function ($leaf, $key) use (&$text): void {
                if ($key === 'value' && is_string($leaf)) {
                    $text[] = $leaf;
                }
            });

            return self::line(implode(' ', $text));
        }

        return $type === 'multi_line_text_field' ? self::text($value) : self::line($value);
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function page(array $node, string $base): array
    {
        $text = self::text($node['body'] ?? '');

        // A page whose content the theme builds from sections (a form, contact details) has an empty
        // body in Shopify: its text is read from the page as shoppers see it.
        if ($text === '' && isset($node['handle'])) {
            $text = self::shown($base.'/pages/'.rawurlencode((string) $node['handle']));
        }
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

    /** The text of the main part of a storefront page, or empty when it cannot be read. */
    private static function shown(string $url): string
    {
        try {
            $response = Http::timeout(15)->connectTimeout(5)->withHeaders(['Accept' => 'text/html'])->get($url);
        } catch (\Throwable) {
            return '';
        }

        if (! $response->successful()) {
            return '';
        }

        $html = (string) $response->body();
        $html = (string) preg_replace('#<(script|style|noscript|svg|template)\b[^>]*>.*?</\1>#is', ' ', $html);
        $main = preg_match('#<main\b[^>]*>(.*)</main>#is', $html, $m) === 1 ? $m[1] : '';
        // Labels and buttons of a form are not what the page says.
        $main = (string) preg_replace('#<(select|textarea|button|label)\b[^>]*>.*?</\1>#is', ' ', $main);
        $main = (string) preg_replace('#<input\b[^>]*>#i', ' ', $main);

        return mb_substr(self::text($main), 0, 20000);
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

    /**
     * The featured picture first, then the next ones: the second for hover, all of them for photo search.
     *
     * @param  list<mixed>  $media
     * @return list<array{url: string, alt: string}>
     */
    private static function images(mixed $featured, array $media): array
    {
        $images = [];

        foreach ([$featured, ...array_map(fn ($m): mixed => data_get($m, 'preview.image'), $media)] as $image) {
            $url = is_array($image) ? ($image['url'] ?? null) : null;

            if (is_string($url) && $url !== '' && ! in_array($url, array_column($images, 'url'), true)) {
                $images[] = ['url' => $url, 'alt' => self::line($image['altText'] ?? '')];
            }
        }

        return array_slice($images, 0, 4);
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
