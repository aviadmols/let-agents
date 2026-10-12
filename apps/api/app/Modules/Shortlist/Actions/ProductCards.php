<?php

namespace App\Modules\Shortlist\Actions;

use App\Modules\Catalog\Models\CatalogProduct;

/**
 * The cards of a few products by the store's own ids, in the order asked, for the compare tray
 * and the wishlist. Products the store removed are left out. Run inside the shop's tenant context.
 */
final class ProductCards
{
    /**
     * @param  list<string>  $externalIds
     * @return list<array{id: string, title: string, url: string|null, image: string|null, price: float|null, regular_price: float|null, currency: string|null, in_stock: bool}>
     */
    public function handle(array $externalIds): array
    {
        if ($externalIds === []) {
            return [];
        }

        $products = CatalogProduct::query()->active()->whereIn('external_id', $externalIds)
            ->get(['id', 'external_id', 'title', 'url', 'image_url', 'price', 'regular_price', 'currency', 'in_stock'])
            ->keyBy('external_id');

        $cards = [];
        foreach ($externalIds as $id) {
            $product = $products->get($id);
            if ($product !== null) {
                $cards[] = [
                    'id' => (string) $product->external_id,
                    'title' => (string) $product->title,
                    'url' => $product->url,
                    'image' => $product->image_url,
                    'price' => $product->price === null ? null : (float) $product->price,
                    'regular_price' => $product->regular_price === null ? null : (float) $product->regular_price,
                    'currency' => $product->currency,
                    'in_stock' => (bool) $product->in_stock,
                ];
            }
        }

        return $cards;
    }

    /**
     * Product ids as a shopper's browser sends them: the store's own ids, distinct, in order.
     *
     * @return list<string>
     */
    public static function ids(mixed $raw, int $max): array
    {
        $raw = is_array($raw) ? $raw : explode(',', (string) $raw);
        $ids = [];
        foreach ($raw as $id) {
            $id = trim((string) $id);
            if (preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $id) === 1 && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return array_slice($ids, 0, $max);
    }
}
