<?php

namespace App\Modules\Recovery\Support;

use App\Modules\Analytics\Models\AnalyticsEvent;
use App\Modules\Analytics\Models\AnalyticsOrder;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Recovery\Models\RecoveryCart;

/**
 * What is known about the visit that led to a cart, condensed in code before any model sees it.
 *
 * Products are short codes (P1, P2…), each with its name, price, category and stock; every fact
 * the code can state is a numbered line (F1, F2…). The model only puts these into words, and code
 * checks that whatever it cites is on the list.
 */
final class CartEvidence
{
    /** How far back the visit is read. */
    private const DAYS = 30;

    private const MAX_VIEWED = 12;

    /**
     * @return array{products: array<string, array<string, mixed>>, facts: array<string, string>, searches: list<string>, thin: bool, codes: array<string, string>}
     */
    public static function for(RecoveryCart $cart): array
    {
        $codes = [];
        $code = function (string $externalId) use (&$codes): string {
            return $codes[$externalId] ??= 'P'.(count($codes) + 1);
        };

        $cartIds = array_values(array_unique(array_column($cart->items, 'product_id')));
        array_map($code, $cartIds);

        $events = $cart->visitor_hash === null ? collect() : AnalyticsEvent::query()
            ->where('visitor_hash', $cart->visitor_hash)
            ->whereBetween('occurred_at', [$cart->captured_at->copy()->subDays(self::DAYS), $cart->captured_at->copy()->addMinutes(5)])
            ->orderBy('occurred_at')
            ->get(['type', 'page_type', 'product_external_id', 'item_external_id', 'model', 'source', 'session_id', 'occurred_at']);

        // Product pages opened, most opened first, the cart's own products aside.
        $views = $events->where('type', 'page_view')->whereNotNull('product_external_id')->countBy('product_external_id')->sortDesc();
        $viewedElsewhere = $views->keys()->reject(fn ($id): bool => in_array((string) $id, $cartIds, true))->take(self::MAX_VIEWED)->values();
        $viewedElsewhere->each(fn ($id) => $code((string) $id));

        $products = self::products($codes, $cartIds, $views->all());
        $facts = [];
        $add = function (string $text) use (&$facts): void {
            $facts['F'.(count($facts) + 1)] = $text;
        };

        $sessions = $events->pluck('session_id')->filter()->unique()->count();
        $first = $events->first()?->occurred_at;

        if ($first !== null) {
            $add('first_visit_days_before_cart='.max(0, (int) $first->diffInDays($cart->captured_at)).'; visits='.max(1, $sessions));
        }

        foreach ($cartIds as $id) {
            $add($codes[$id].' in cart; product page opened '.($views[$id] ?? 0).' times');
        }

        foreach ($viewedElsewhere as $id) {
            $add($codes[(string) $id].' viewed '.$views[$id].' times, not in cart');
        }

        $widgetAdds = $events->where('type', 'add_to_cart')->where('source', 'widget')->pluck('item_external_id')->filter()->unique();
        foreach ($widgetAdds as $id) {
            if (isset($codes[(string) $id])) {
                $add($codes[(string) $id].' added from the shop assistant\'s suggestions');
            }
        }

        $clicks = $events->where('type', 'click')->count();
        $questions = $events->where('type', 'chat_question')->count();
        if ($clicks > 0 || $questions > 0) {
            $add('assistant: '.$clicks.' suggestions clicked, '.$questions.' questions asked');
        }

        // Something cheaper of the same kind was looked at: a price hesitation code can state.
        foreach ($cartIds as $id) {
            $inCart = $products[$codes[$id]] ?? null;

            foreach ($viewedElsewhere as $other) {
                $seen = $products[$codes[(string) $other]] ?? null;

                if ($inCart && $seen && $inCart['cat'] !== null && $inCart['cat'] === $seen['cat'] && $seen['price'] !== null && $inCart['price'] !== null && $seen['price'] < $inCart['price']) {
                    $add($codes[(string) $other].' is cheaper than '.$codes[$id].' in the same category ('.$seen['price'].' vs '.$inCart['price'].')');
                }
            }

            if ($inCart && $inCart['stock'] === false) {
                $add($codes[$id].' is out of stock now');
            }
        }

        $orders = $cart->visitor_hash === null ? 0 : AnalyticsOrder::query()->where('visitor_hash', $cart->visitor_hash)->where('ordered_at', '<', $cart->captured_at)->count();
        $add($orders > 0 ? 'returning customer: '.$orders.' earlier orders' : 'no earlier order from this browser');

        $average = (float) AnalyticsOrder::query()->where('ordered_at', '>=', now()->subDays(90))->avg('total');
        if ($average > 0) {
            $add('cart total '.round((float) $cart->total).' vs shop average order '.round($average));
        }

        $searches = array_values(array_unique(array_map(fn (array $s): string => $s['q'], (array) $cart->searches)));

        return [
            'products' => $products,
            'facts' => $facts,
            'searches' => $searches,
            // Nothing beyond the cart itself: the code says so, no model is asked.
            'thin' => $events->isEmpty() && $searches === [],
            'codes' => array_flip($codes),
        ];
    }

    /**
     * @param  array<string, string>  $codes  external id → code
     * @param  list<string>  $cartIds
     * @param  array<string, int>  $views
     * @return array<string, array{name: string, price: float|null, cat: string|null, brand: string|null, stock: bool|null, cart: bool}>
     */
    private static function products(array $codes, array $cartIds, array $views): array
    {
        $rows = CatalogProduct::query()->whereIn('external_id', array_keys($codes))
            ->with('categories:id,name')->get(['id', 'external_id', 'title', 'price', 'brand', 'in_stock'])->keyBy('external_id');
        $out = [];

        foreach ($codes as $externalId => $c) {
            $row = $rows->get($externalId);
            $out[$c] = [
                'name' => mb_substr((string) ($row?->title ?? '#'.$externalId), 0, 90),
                'price' => $row?->price !== null ? (float) $row->price : null,
                'cat' => $row?->categories->first()?->name,
                'brand' => $row?->brand,
                'stock' => $row?->in_stock,
                'cart' => in_array((string) $externalId, $cartIds, true),
            ];
        }

        return $out;
    }
}
