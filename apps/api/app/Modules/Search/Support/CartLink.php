<?php

namespace App\Modules\Search\Support;

use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Connections\Models\StoreConnection;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * A link that puts products in the store's own cart, for a shopper's AI to hand back: the shopper
 * opens it, sees the cart on the store and pays there. Nothing is bought here and no payment
 * detail is ever asked for.
 *
 * Shopify: one cart permalink for every item (/cart/{variant}:{qty},…), marked as coming from an
 * AI (cart attribute la_source=ai). A product without options has its one variant read live from
 * its public product page. WooCommerce: one add-to-cart link per item.
 * Inside the shop's tenant.
 */
final class CartLink
{
    public const MAX_ITEMS = 10;

    public const MAX_QUANTITY = 20;

    /**
     * @param  list<array<string, mixed>>  $items  each {product, variant?, quantity?}
     * @return array{url: string|null, links: list<array{product: string, url: string}>, problems: list<array{product: string, problem: string, options?: list<array{id: string, title: string, in_stock: bool}>}>}
     */
    public static function build(StoreConnection $connection, array $items): array
    {
        $base = rtrim((string) $connection->site_url, '/');
        $lines = [];
        $links = [];
        $problems = [];

        foreach (array_slice($items, 0, self::MAX_ITEMS) as $item) {
            $productId = (string) ($item['product'] ?? '');
            $quantity = max(1, min(self::MAX_QUANTITY, (int) ($item['quantity'] ?? 1)));
            $product = $productId === '' ? null : CatalogProduct::query()->where('external_id', $productId)->whereNull('removed_at')->first();

            if ($product === null) {
                $problems[] = ['product' => $productId, 'problem' => 'unknown_product'];

                continue;
            }

            $options = array_values(array_filter(array_map(fn ($v): ?array => is_array($v) && ($v['external_id'] ?? '') !== ''
                ? ['id' => (string) $v['external_id'], 'title' => (string) ($v['title'] ?? ''), 'in_stock' => (bool) ($v['stock']['in_stock'] ?? false)]
                : null, (array) (((array) $product->payload)['variations'] ?? []))));
            $variant = (string) ($item['variant'] ?? '');

            if ($options !== [] && ! in_array($variant, array_column($options, 'id'), true)) {
                // An option must be chosen ("מידה 7"), and only one the product has.
                $problems[] = ['product' => $productId, 'problem' => $variant === '' ? 'choose_option' : 'unknown_option', 'options' => $options];

                continue;
            }

            if ($connection->platform === 'shopify') {
                $variant = $variant !== '' ? $variant : ((string) (((array) $product->payload)['variant_id'] ?? '') ?: self::onlyVariant($product));
                if ($variant === null) {
                    $problems[] = ['product' => $productId, 'problem' => 'not_available'];

                    continue;
                }
                $lines[] = $variant.':'.$quantity;
                $links[] = ['product' => $productId, 'url' => (string) self::buyUrl(['platform' => 'shopify', 'url' => $base], $productId, $variant, $quantity)];

                continue;
            }

            $links[] = ['product' => $productId, 'url' => (string) self::buyUrl(['platform' => (string) $connection->platform, 'url' => $base], $productId, $variant ?: null, $quantity)];
        }

        $url = $connection->platform === 'shopify'
            ? ($lines === [] ? null : $base.'/cart/'.implode(',', $lines).'?attributes[la_source]=ai')
            : ($links[0]['url'] ?? null);

        return ['url' => $url, 'links' => $links, 'problems' => $problems];
    }

    /**
     * The link that buys one product (or one of its options) at once: on Shopify the store's
     * checkout with it already in the cart, marked as from an AI; on WooCommerce the store with it
     * added to the cart. Null on Shopify without a variant to buy. No request is made.
     *
     * @param  array{platform: string, url: string}  $store
     */
    public static function buyUrl(array $store, string $productId, ?string $variantId, int $quantity = 1): ?string
    {
        $base = rtrim($store['url'], '/');
        $quantity = max(1, min(self::MAX_QUANTITY, $quantity));

        if ($store['platform'] === 'shopify') {
            return $variantId === null || $variantId === '' ? null : $base.'/cart/'.$variantId.':'.$quantity.'?attributes[la_source]=ai';
        }

        return $base.'/?'.http_build_query(array_filter(['add-to-cart' => $productId, 'variation_id' => $variantId ?: null, 'quantity' => $quantity > 1 ? $quantity : null]));
    }

    /** The one variant of a Shopify product without options, from its public product page, when it can be bought. */
    private static function onlyVariant(CatalogProduct $product): ?string
    {
        if (! is_string($product->url) || $product->url === '') {
            return null;
        }

        try {
            $json = Http::timeout(8)->acceptJson()->get(rtrim($product->url, '/').'.js')->json();
        } catch (Throwable) {
            return null;
        }

        foreach ((array) ($json['variants'] ?? []) as $variant) {
            if (is_array($variant) && ($variant['available'] ?? false) === true && isset($variant['id'])) {
                return (string) $variant['id'];
            }
        }

        return null;
    }
}
