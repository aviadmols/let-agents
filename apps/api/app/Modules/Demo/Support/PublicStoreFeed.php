<?php

namespace App\Modules\Demo\Support;

use App\Modules\Connections\Contracts\PlatformStoreFeed;
use App\Modules\Connections\Contracts\StoreFeedUnavailable;
use App\Modules\Connections\Models\StoreConnection;
use Generator;

/**
 * A demo store's catalogue, from what the store publishes for anyone (platform "demo"): Shopify's public product
 * feed, WooCommerce's public store API and WordPress's pages and posts, or, on any other site, the products its
 * sitemap lists, read from the product data each page carries (JSON-LD). In the feed shape every other reader
 * gives (FeedRecord), so the catalogue's sync, the search and the assistant take it as they take a real store.
 * How many is the shop's own limit (catalog.max_products, catalog.max_content_items): the sync stops reading there.
 * A Shopify store's pages and policies come from its site's pages (ReadSitePages), as for a real Shopify store.
 */
final class PublicStoreFeed implements PlatformStoreFeed
{
    /** A pause between pages: a demo never weighs on the store's server. */
    private const PAUSE_MS = 400;

    /** Product pages read for a site with no public feed, at most. */
    private const SITEMAP_PRODUCTS = 300;

    public function platform(): string
    {
        return 'demo';
    }

    public function categories(StoreConnection $connection): array
    {
        $host = self::hostOf($connection);

        return match (self::kind($connection)) {
            PublicSite::SHOPIFY => array_values(array_map(fn (array $c): array => [
                'external_id' => (string) $c['id'], 'name' => (string) $c['title'], 'parent_id' => null, 'path' => [(string) $c['title']],
                'product_count' => (int) ($c['products_count'] ?? 0), 'url' => 'https://'.$host.'/collections/'.$c['handle'], 'image' => $c['image']['src'] ?? null,
            ], array_filter((array) (PublicSite::json($host, '/collections.json', ['limit' => 250])['collections'] ?? []), 'is_array'))),
            PublicSite::WOOCOMMERCE => array_values(array_map(fn (array $c): array => [
                'external_id' => (string) $c['id'], 'name' => html_entity_decode((string) $c['name'], ENT_QUOTES), 'parent_id' => ($c['parent'] ?? 0) ? (string) $c['parent'] : null,
                'path' => [html_entity_decode((string) $c['name'], ENT_QUOTES)], 'product_count' => (int) ($c['count'] ?? 0), 'url' => $c['permalink'] ?? null, 'image' => $c['image']['src'] ?? null,
            ], array_filter((array) PublicSite::json($host, '/wp-json/wc/store/v1/products/categories', ['per_page' => 100]), 'is_array'))),
            default => [],
        };
    }

    public function products(StoreConnection $connection): Generator
    {
        $host = self::hostOf($connection);
        $kind = self::kind($connection);

        if ($kind === PublicSite::SHOPIFY) {
            for ($page = 1; $page <= 40; $page++) {
                $products = (array) (PublicSite::json($host, '/products.json', ['limit' => 250, 'page' => $page])['products'] ?? []);
                if ($products === []) {
                    return;
                }
                yield array_values(array_map(fn (array $p): array => self::shopify($p, $host, $connection), array_filter($products, 'is_array')));
                usleep(self::PAUSE_MS * 1000);
            }

            return;
        }

        if ($kind === PublicSite::WOOCOMMERCE) {
            for ($page = 1; $page <= 100; $page++) {
                $products = PublicSite::json($host, '/wp-json/wc/store/v1/products', ['per_page' => 100, 'page' => $page]);
                if ($products === null && $page === 1) {
                    throw new StoreFeedUnavailable('unreachable', null, 'store api');
                }
                if ($products === null || $products === []) {
                    return;
                }
                yield array_values(array_map(fn (array $p): array => self::woocommerce($p), array_filter($products, 'is_array')));
                usleep(self::PAUSE_MS * 1000);
            }

            return;
        }

        $batch = [];
        foreach (array_slice(Sitemap::urls($host, 'product'), 0, self::SITEMAP_PRODUCTS) as $url) {
            $record = self::fromPage($host, $url, $connection);
            if ($record !== null) {
                $batch[] = $record;
            }
            if (count($batch) >= 25) {
                yield $batch;
                $batch = [];
            }
            usleep(self::PAUSE_MS * 1000);
        }
        if ($batch !== []) {
            yield $batch;
        }
    }

    public function content(StoreConnection $connection, string $type): Generator
    {
        // WordPress shares its pages and posts publicly; a Shopify or other site's come from its sitemap (ReadSitePages)
        if (self::kind($connection) !== PublicSite::WOOCOMMERCE) {
            return;
        }

        $host = self::hostOf($connection);
        for ($page = 1; $page <= 20; $page++) {
            $items = PublicSite::json($host, '/wp-json/wp/v2/'.($type === 'post' ? 'posts' : 'pages'), ['per_page' => 50, 'page' => $page, '_fields' => 'id,link,slug,title,content,excerpt,date,modified']);
            if ($items === null || $items === []) {
                return;
            }
            yield array_values(array_map(fn (array $c): array => [
                'external_id' => (string) $c['id'], 'type' => $type, 'title' => html_entity_decode(strip_tags((string) ($c['title']['rendered'] ?? '')), ENT_QUOTES | ENT_HTML5),
                'slug' => $c['slug'] ?? null, 'url' => $c['link'] ?? null, 'excerpt' => PublicSite::text((string) ($c['excerpt']['rendered'] ?? '')),
                'text' => PublicSite::text((string) ($c['content']['rendered'] ?? '')), 'image' => null, 'terms' => [], 'product_ids' => [],
                'created_at' => $c['date'] ?? null, 'updated_at' => $c['modified'] ?? null,
            ], array_filter($items, 'is_array')));
            usleep(self::PAUSE_MS * 1000);
        }
    }

    public function contentTypes(StoreConnection $connection): array
    {
        return self::kind($connection) === PublicSite::WOOCOMMERCE ? ['page', 'post'] : [];
    }

    private static function hostOf(StoreConnection $connection): string
    {
        return (string) PublicSite::host((string) $connection->site_url);
    }

    private static function kind(StoreConnection $connection): string
    {
        return (string) (((array) $connection->site_info)['demo']['platform'] ?? PublicSite::OTHER);
    }

    private static function currency(StoreConnection $connection): string
    {
        return (string) (((array) $connection->site_info)['demo']['currency'] ?? 'ILS');
    }

    /** @param array<string, mixed> $p one product of Shopify's public products.json */
    private static function shopify(array $p, string $host, StoreConnection $connection): array
    {
        $variants = array_values(array_filter((array) ($p['variants'] ?? []), 'is_array'));
        $options = array_values(array_filter((array) ($p['options'] ?? []), 'is_array'));
        $prices = array_map(fn (array $v): float => (float) ($v['price'] ?? 0), $variants) ?: [0.0];
        $first = $variants[0] ?? [];
        $price = (float) ($first['price'] ?? min($prices));
        $compare = (float) ($first['compare_at_price'] ?? 0);
        $available = array_any($variants, fn (array $v): bool => (bool) ($v['available'] ?? false));
        $tags = is_array($p['tags'] ?? null) ? $p['tags'] : array_filter(array_map('trim', explode(',', (string) ($p['tags'] ?? ''))));
        $type = trim((string) ($p['product_type'] ?? ''));
        $currency = self::currency($connection);

        return [
            'external_id' => (string) $p['id'],
            'type' => count($variants) > 1 ? 'variable' : 'simple',
            'status' => 'publish',
            'title' => (string) ($p['title'] ?? ''),
            'slug' => $p['handle'] ?? null,
            'handle' => $p['handle'] ?? null,
            'url' => 'https://'.$host.'/products/'.($p['handle'] ?? ''),
            'sku' => $first['sku'] ?? null,
            'short_description' => '',
            'description' => PublicSite::text((string) ($p['body_html'] ?? '')),
            'images' => array_slice(array_values(array_map(fn (array $i): array => ['url' => (string) ($i['src'] ?? ''), 'alt' => (string) ($i['alt'] ?? '')], array_filter((array) ($p['images'] ?? []), 'is_array'))), 0, 4),
            'categories' => $type === '' ? [] : [['id' => 'type:'.$type, 'name' => $type, 'slug' => $type, 'path' => [$type]]],
            'tags' => array_values(array_map('strval', $tags)),
            'brands' => array_filter([(string) ($p['vendor'] ?? '')]),
            'vendor' => $p['vendor'] ?? null,
            'product_type' => $type,
            'attributes' => array_map(fn (array $o): array => ['name' => (string) $o['name'], 'key' => (string) $o['name'], 'taxonomy' => false, 'values' => array_values(array_map('strval', (array) ($o['values'] ?? []))), 'used_for_variations' => true, 'visible' => true], array_filter($options, fn (array $o): bool => ($o['name'] ?? '') !== 'Title')),
            'meta' => [],
            'relations' => [],
            'price' => ['currency' => $currency, 'price' => (string) $price, 'regular_price' => (string) max($price, $compare), 'on_sale' => $compare > $price, 'min_price' => (string) min($prices), 'max_price' => (string) max($prices)],
            'stock' => ['in_stock' => $available, 'managed' => false, 'quantity' => null],
            'dimensions' => ['weight' => isset($first['grams']) ? (string) ((int) $first['grams'] / 1000) : '', 'weight_unit' => 'kg'],
            'variations' => count($variants) > 1 ? array_map(fn (array $v): array => [
                'external_id' => (string) $v['id'], 'title' => (string) ($v['title'] ?? ''), 'sku' => $v['sku'] ?? null,
                'attributes' => array_values(array_filter(array_map(fn (int $i): ?array => isset($v['option'.$i], $options[$i - 1]) ? ['name' => (string) $options[$i - 1]['name'], 'value' => (string) $v['option'.$i]] : null, [1, 2, 3]))),
                'price' => ['price' => (string) ($v['price'] ?? ''), 'regular_price' => (string) max((float) ($v['price'] ?? 0), (float) ($v['compare_at_price'] ?? 0)), 'on_sale' => (float) ($v['compare_at_price'] ?? 0) > (float) ($v['price'] ?? 0)],
                'stock' => ['in_stock' => (bool) ($v['available'] ?? false)], 'purchasable' => (bool) ($v['available'] ?? false),
            ], $variants) : [],
            'purchasable' => $available,
            'created_at' => $p['created_at'] ?? null,
            'updated_at' => $p['updated_at'] ?? null,
        ];
    }

    /** @param array<string, mixed> $p one product of WooCommerce's public store API */
    private static function woocommerce(array $p): array
    {
        $prices = (array) ($p['prices'] ?? []);
        $unit = 10 ** (int) ($prices['currency_minor_unit'] ?? 2);
        $money = fn ($v): string => is_numeric($v) ? (string) ((float) $v / $unit) : '';
        $attributes = array_values(array_filter((array) ($p['attributes'] ?? []), 'is_array'));

        return [
            'external_id' => (string) $p['id'],
            'type' => ($p['type'] ?? 'simple') === 'variable' ? 'variable' : 'simple',
            'status' => 'publish',
            'title' => html_entity_decode((string) ($p['name'] ?? ''), ENT_QUOTES | ENT_HTML5),
            'slug' => $p['slug'] ?? null,
            'url' => $p['permalink'] ?? null,
            'sku' => ($p['sku'] ?? '') !== '' ? $p['sku'] : null,
            'short_description' => PublicSite::text((string) ($p['short_description'] ?? '')),
            'description' => PublicSite::text((string) ($p['description'] ?? '')),
            'images' => array_slice(array_values(array_map(fn (array $i): array => ['url' => (string) ($i['src'] ?? ''), 'alt' => (string) ($i['alt'] ?? '')], array_filter((array) ($p['images'] ?? []), 'is_array'))), 0, 4),
            'categories' => array_values(array_map(fn (array $c): array => ['id' => (string) $c['id'], 'name' => html_entity_decode((string) $c['name'], ENT_QUOTES), 'slug' => $c['slug'] ?? null, 'path' => [html_entity_decode((string) $c['name'], ENT_QUOTES)]], array_filter((array) ($p['categories'] ?? []), 'is_array'))),
            'tags' => array_values(array_map(fn (array $t): string => html_entity_decode((string) ($t['name'] ?? ''), ENT_QUOTES), array_filter((array) ($p['tags'] ?? []), 'is_array'))),
            'brands' => array_values(array_map(fn (array $b): string => (string) ($b['name'] ?? ''), array_filter((array) ($p['brands'] ?? []), 'is_array'))),
            'attributes' => array_map(fn (array $a): array => ['name' => (string) $a['name'], 'key' => (string) ($a['taxonomy'] ?? $a['name']), 'taxonomy' => (bool) ($a['taxonomy'] ?? false), 'values' => array_values(array_map(fn ($t): string => (string) ($t['name'] ?? ''), (array) ($a['terms'] ?? []))), 'used_for_variations' => (bool) ($a['has_variations'] ?? false), 'visible' => true], $attributes),
            'meta' => [],
            'relations' => [],
            'price' => ['currency' => (string) ($prices['currency_code'] ?? 'ILS'), 'price' => $money($prices['price'] ?? null), 'regular_price' => $money($prices['regular_price'] ?? null), 'on_sale' => (bool) ($p['on_sale'] ?? false),
                'min_price' => $money($prices['price_range']['min_amount'] ?? $prices['price'] ?? null), 'max_price' => $money($prices['price_range']['max_amount'] ?? $prices['price'] ?? null)],
            'stock' => ['in_stock' => (bool) ($p['is_in_stock'] ?? true), 'managed' => false, 'quantity' => null],
            'variations' => [],
            'purchasable' => (bool) ($p['is_purchasable'] ?? true),
            'created_at' => null,
            'updated_at' => null,
        ];
    }

    /** A product page's own product data (JSON-LD), for a site with no public feed; null when it carries none. */
    private static function fromPage(string $host, string $url, StoreConnection $connection): ?array
    {
        $html = (string) PublicSite::get($host, $url)?->body();
        preg_match_all('~<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>~is', $html, $m);
        foreach ($m[1] as $json) {
            $data = json_decode(html_entity_decode(trim($json)), true);
            $nodes = is_array($data) ? (isset($data['@graph']) ? (array) $data['@graph'] : (array_is_list($data) ? $data : [$data])) : [];
            foreach ($nodes as $node) {
                if (! is_array($node) || ! in_array('Product', (array) ($node['@type'] ?? []), true)) {
                    continue;
                }
                $offer = (array) (array_is_list((array) ($node['offers'] ?? [])) ? ($node['offers'][0] ?? []) : ($node['offers'] ?? []));
                $images = array_slice(array_values(array_filter(array_map(fn ($i): string => is_array($i) ? (string) ($i['url'] ?? '') : (string) $i, (array) ($node['image'] ?? [])))), 0, 4);

                return [
                    'external_id' => substr(sha1($url), 0, 16), 'type' => 'simple', 'status' => 'publish', 'title' => (string) ($node['name'] ?? ''), 'url' => $url,
                    'sku' => $node['sku'] ?? null, 'short_description' => '', 'description' => PublicSite::text((string) ($node['description'] ?? '')),
                    'images' => array_map(fn (string $i): array => ['url' => $i, 'alt' => ''], $images), 'categories' => [], 'tags' => [],
                    'brands' => array_filter([is_array($node['brand'] ?? null) ? (string) ($node['brand']['name'] ?? '') : (string) ($node['brand'] ?? '')]),
                    'attributes' => [], 'meta' => [], 'relations' => [],
                    'price' => ['currency' => (string) ($offer['priceCurrency'] ?? self::currency($connection)), 'price' => (string) ($offer['price'] ?? ''), 'regular_price' => (string) ($offer['price'] ?? ''), 'on_sale' => false, 'min_price' => (string) ($offer['price'] ?? ''), 'max_price' => (string) ($offer['price'] ?? '')],
                    'stock' => ['in_stock' => ! str_contains(strtolower((string) ($offer['availability'] ?? 'InStock')), 'outofstock'), 'managed' => false, 'quantity' => null],
                    'variations' => [], 'purchasable' => true, 'created_at' => null, 'updated_at' => null,
                ];
            }
        }

        return null;
    }
}
