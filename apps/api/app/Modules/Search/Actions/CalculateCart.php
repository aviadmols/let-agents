<?php

namespace App\Modules\Search\Actions;

use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Catalog\Models\CatalogProductChoice;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Search\Support\AgentCatalog;
use App\Modules\Search\Support\CartLink;
use App\Modules\Search\Support\OrderItems;

/**
 * An order as the store will take it, every line checked in code: the product is the store's, the
 * option or length chosen is one it sells, it is in stock, and never more of it than the store
 * has (asked for more: cut to what it has, and said). Each line with its price and how to put it
 * in the store's own cart from the store's own page: a Shopify variant, or the fields a
 * WooCommerce product form posts (its variation, the length a page asks for). A line that still
 * needs a choice is said to, with every choice priced, never guessed.
 *
 * Two totals: what goes into the cart now, and the whole order (a line still to choose counted
 * at its cheapest choice, and said to be an estimate). Shipping is never counted: the store's
 * checkout says it. No model is asked. Inside the shop's tenant.
 */
final class CalculateCart
{
    public const READY = 'ready';

    public const REDUCED = 'quantity_reduced';

    public const CHOOSE = 'requires_manual_option_selection';

    public const OUT = 'out_of_stock';

    public const UNKNOWN = 'unknown_product';

    /**
     * @param  list<array<string, mixed>>  $items  OrderItems' shape, names already found
     * @return array<string, mixed>
     */
    public function handle(StoreConnection $connection, array $items): array
    {
        $shopify = $connection->platform === 'shopify';
        $lines = [];
        $currency = 'ILS';

        foreach (array_slice(array_values($items), 0, OrderItems::MAX_LINES) as $n => $item) {
            $line = $this->line($shopify, $item) + ['line' => $n + 1];
            $currency = $line['currency'] ?? $currency;
            unset($line['currency']);
            $lines[] = $line;
        }

        $now = array_filter($lines, fn (array $l): bool => $l['auto']);
        $later = array_filter($lines, fn (array $l): bool => $l['status'] === self::CHOOSE);
        $auto = round(array_sum(array_column($now, 'line_total')), 2);
        $estimate = round(array_sum(array_map(fn (array $l): float => (float) ($l['from_total'] ?? 0), $later)), 2);

        return [
            'currency' => $currency,
            'lines' => $lines,
            'totals' => [
                // What goes into the cart now, at once.
                'auto_total' => $auto,
                // The whole order: a line still to choose at its cheapest choice.
                'order_total' => round($auto + $estimate, 2),
                'order_total_is_estimate' => $later !== [],
                'lines' => count($lines),
                'auto_lines' => count($now),
                'manual_lines' => count($later),
                'unavailable_lines' => count(array_filter($lines, fn (array $l): bool => in_array($l['status'], [self::OUT, self::UNKNOWN], true))),
            ],
            'shipping' => 'calculated_at_checkout',
            'platform' => $shopify ? 'shopify' : 'woocommerce',
            'cart_url' => CartLink::cartUrl($connection),
            'order_url' => self::orderUrl($connection, $lines),
            'as_of' => AgentCatalog::asOf(array_values(array_filter(array_column($lines, 'product')))),
        ];
    }

    /** The order as a link on the store's own address, which our script there completes. @param list<array<string, mixed>> $lines */
    public static function orderUrl(StoreConnection $connection, array $lines): string
    {
        $parts = array_map(fn (array $l): string => $l['product']
            .(isset($l['option']['id']) ? '/'.$l['option']['id'] : '')
            .(isset($l['length']['metres']) ? '@'.$l['length']['metres'] : '')
            .':'.max(1, (int) ($l['quantity'] ?: $l['quantity_asked'])),
            array_filter($lines, fn (array $l): bool => ($l['product'] ?? '') !== '' && $l['status'] !== self::UNKNOWN));

        return rtrim((string) $connection->site_url, '/').'/?la_order='.rawurlencode(implode(',', $parts));
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function line(bool $shopify, array $item): array
    {
        $id = (string) ($item['product'] ?? '');
        $asked = max(1, (int) ($item['quantity'] ?? 1));
        $product = $id === '' ? null : CatalogProduct::query()->where('external_id', $id)->whereNull('removed_at')->first();

        if ($product === null) {
            return ['product' => $id, 'status' => self::UNKNOWN, 'auto' => false, 'quantity_asked' => $asked, 'quantity' => 0, 'line_total' => 0.0];
        }

        $payload = (array) $product->payload;
        $base = array_filter([
            'product' => (string) $product->external_id,
            'title' => (string) $product->title,
            'image' => $product->image_url,
            'page_url' => $product->url,
            'quantity_asked' => $asked,
            'currency' => $product->currency ?: 'ILS',
        ], fn ($v): bool => $v !== null && $v !== '');
        $price = $product->price === null ? null : (float) $product->price;
        $variants = array_values(array_filter((array) ($payload['variations'] ?? []), fn ($v): bool => is_array($v) && ($v['external_id'] ?? '') !== ''));
        $asks = CatalogProductChoice::query()->where('product_id', $product->id)->first();
        $field = $asks !== null && $asks->required() ? collect($asks->fields)->first(fn (array $f): bool => (bool) ($f['required'] ?? false)) : null;

        // A product with options: the option chosen, or every option to choose from.
        if ($variants !== []) {
            $chosen = collect($variants)->first(fn (array $v): bool => (string) $v['external_id'] === (string) ($item['variant'] ?? ''));
            $add = fn (array $v, int $quantity): array => $shopify
                ? ['variant' => (string) $v['external_id'], 'quantity' => $quantity]
                : ['post_url' => (string) $product->url, 'fields' => ['add-to-cart' => (string) $product->external_id, 'variation_id' => (string) $v['external_id'], 'quantity' => (string) $quantity]];

            if ($chosen === null) {
                return $base + $this->toChoose(array_map(fn (array $v): array => array_filter([
                    'option' => (string) $v['external_id'],
                    'label' => CatalogProduct::variantLabel($v),
                    'price' => is_numeric($v['price']['price'] ?? null) ? (float) $v['price']['price'] : $price,
                    'in_stock' => (bool) ($v['stock']['in_stock'] ?? false),
                    'add' => $add($v, $asked),
                ], fn ($x): bool => $x !== null), $variants), $asked, ($item['variant'] ?? '') !== '' ? 'unknown_option' : null);
            }

            return $base + $this->priced(
                $asked,
                is_numeric($chosen['price']['price'] ?? null) ? (float) $chosen['price']['price'] : $price,
                (bool) ($chosen['stock']['in_stock'] ?? false),
                AgentCatalog::units((array) ($chosen['stock'] ?? [])) ?? AgentCatalog::units((array) ($payload['stock'] ?? [])),
                fn (int $quantity): array => $add($chosen, $quantity),
            ) + ['option' => ['id' => (string) $chosen['external_id'], 'title' => CatalogProduct::variantLabel($chosen)]];
        }

        // A choice its page asks for (a length): the one chosen, or every one to choose from.
        if ($field !== null) {
            $values = (array) ($field['values'] ?? []);
            $length = is_numeric($item['length'] ?? null) ? (float) $item['length'] : null;
            $picked = $length === null ? null : collect($values)->first(fn (array $v): bool => isset($v['meters']) && abs((float) $v['meters'] - $length) < 0.001);
            $add = fn (array $v, int $quantity): array => ['post_url' => (string) $product->url, 'fields' => [
                'add-to-cart' => (string) $product->external_id, 'quantity' => (string) $quantity, (string) $field['key'] => (string) $v['value'],
            ]];

            if ($picked === null) {
                return $base + $this->toChoose(array_map(fn (array $v): array => array_filter([
                    'length' => isset($v['meters']) ? (float) $v['meters'] : null,
                    'label' => (string) ($v['label'] ?? ''),
                    'price' => isset($v['price']) ? (float) $v['price'] : null,
                    'in_stock' => (bool) $product->in_stock,
                    'add' => $add($v, $asked),
                ], fn ($x): bool => $x !== null), $values), $asked, $length !== null ? 'unknown_length' : null) + ['field' => (string) ($field['label'] ?? '')];
            }

            return $base + $this->priced(
                $asked,
                isset($picked['price']) ? (float) $picked['price'] : $price,
                (bool) $product->in_stock,
                AgentCatalog::units((array) ($payload['stock'] ?? [])),
                fn (int $quantity): array => $add($picked, $quantity),
            ) + ['length' => ['metres' => (float) ($picked['meters'] ?? 0), 'label' => (string) ($picked['label'] ?? '')], 'field' => (string) ($field['label'] ?? '')];
        }

        // A product without options: its one variant on Shopify (from the sync, or its public page).
        $variant = $shopify ? ((string) ($payload['variant_id'] ?? '') ?: CartLink::onlyVariant($product)) : null;

        return $base + $this->priced(
            $asked,
            $price,
            (bool) $product->in_stock && (! $shopify || $variant !== null),
            AgentCatalog::units((array) ($payload['stock'] ?? [])),
            fn (int $quantity): array => $shopify
                ? ['variant' => (string) $variant, 'quantity' => $quantity]
                : ['post_url' => (string) $product->url, 'fields' => ['add-to-cart' => (string) $product->external_id, 'quantity' => (string) $quantity]],
        );
    }

    /**
     * A line chosen in full, its stock held in code: none, out of stock; asked for more than the
     * store has, cut to what it has.
     *
     * @param  callable(int): array<string, mixed>  $add
     * @return array<string, mixed>
     */
    private function priced(int $asked, ?float $price, bool $inStock, ?int $units, callable $add): array
    {
        if (! $inStock || $units === 0) {
            return ['status' => self::OUT, 'auto' => false, 'quantity' => 0, 'unit_price' => $price, 'line_total' => 0.0, 'stock' => $units];
        }

        $quantity = $units !== null ? min($asked, $units) : $asked;

        return array_filter([
            'status' => $quantity < $asked ? self::REDUCED : self::READY,
            'auto' => $price !== null,
            'quantity' => $quantity,
            'unit_price' => $price,
            'line_total' => $price === null ? 0.0 : round($price * $quantity, 2),
            'stock' => $units,
            'add' => $add($quantity),
        ], fn ($v): bool => $v !== null);
    }

    /**
     * A line still to choose: every choice with its price, in stock first; counted at the cheapest.
     *
     * @param  list<array<string, mixed>>  $choices
     * @return array<string, mixed>
     */
    private function toChoose(array $choices, int $asked, ?string $problem): array
    {
        $available = array_values(array_filter($choices, fn (array $c): bool => (bool) ($c['in_stock'] ?? false)));
        $prices = array_values(array_filter(array_map(fn (array $c): ?float => $c['price'] ?? null, $available), fn ($p): bool => $p !== null));

        if ($available === []) {
            return ['status' => self::OUT, 'auto' => false, 'quantity' => 0, 'line_total' => 0.0, 'choose' => $choices];
        }

        return array_filter([
            'status' => self::CHOOSE,
            'auto' => false,
            'quantity' => $asked,
            'line_total' => 0.0,
            'from_price' => $prices === [] ? null : min($prices),
            'from_total' => $prices === [] ? null : round(min($prices) * $asked, 2),
            'problem' => $problem,
            'choose' => [...$available, ...array_values(array_filter($choices, fn (array $c): bool => ! ($c['in_stock'] ?? false)))],
        ], fn ($v): bool => $v !== null);
    }
}
