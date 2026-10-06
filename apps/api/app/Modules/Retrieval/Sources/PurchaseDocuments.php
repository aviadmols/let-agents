<?php

namespace App\Modules\Retrieval\Sources;

use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Retrieval\Contracts\DocumentSource;
use App\Modules\Retrieval\Contracts\SourceDocument;
use App\Modules\Retrieval\Enums\ChunkSource;
use App\Modules\Retrieval\Support\Purchases;

/**
 * What the orders say about each product that sold: in how many orders, and what was bought
 * with it, by name, with how often and the lift. One document per product sold, so "what do
 * people buy with a cordless drill" finds an answer the way "what is a cordless drill" does.
 *
 * Counts are rounded into bands so the text — and the vector — only changes when the picture
 * does, not with every single order.
 */
final class PurchaseDocuments implements DocumentSource
{
    private const PARTNERS = 8;

    public function key(): string
    {
        return ChunkSource::Purchases->value;
    }

    public function documents(string $shopId): iterable
    {
        $purchases = Purchases::read($shopId);

        if ($purchases->totalOrders() === 0) {
            return;
        }

        $products = CatalogProduct::query()->active()->get(['id', 'external_id', 'title'])->keyBy('external_id');

        foreach ($purchases->bestSellers() as $externalId => $orders) {
            $product = $products->get((string) $externalId);

            if ($product === null) {
                continue;
            }

            $lines = ['Bought in about '.self::band($orders).' orders.'];
            $partners = [];

            foreach ($purchases->partnersOf((string) $externalId, self::PARTNERS * 2) as $partner) {
                $other = $products->get($partner['external_id']);

                if ($other !== null && count($partners) < self::PARTNERS) {
                    $partners[] = '- '.$other->title.' (in about '.self::band($partner['together']).' of the same orders, lift '.number_format($partner['lift'], 1).')';
                }
            }

            if ($partners !== []) {
                $lines[] = 'Often bought together with:';
                array_push($lines, ...$partners);
            }

            yield new SourceDocument($product->id, $product->external_id, $product->title, implode("\n", $lines));
        }
    }

    /** 1, 2, 3, 5, 10, 20, 50, 100, 200, 500... the nearest step below. */
    public static function band(int $count): int
    {
        foreach ([1000, 500, 200, 100, 50, 20, 10, 5, 3, 2, 1] as $step) {
            if ($count >= $step) {
                return $step;
            }
        }

        return 0;
    }
}
