<?php

namespace App\Modules\Search\Support;

use App\Core\Facades\Settings;
use App\Modules\Assistant\Contracts\ProductsPopular;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Search\Models\SearchClick;
use App\Modules\Search\Models\SearchSignal;

/**
 * How popular a store's products are: what each sold, as the store counts it (its feed's "sales"), and what its shoppers
 * opened and added to the cart from its search in the last search.popular_days (each shopper once a day where the
 * signals count them). Sales weigh SALES_SHARE of the score where the store sends them, its search all of it where
 * not; an add to the cart ADD_WEIGHT opens. Code only.
 */
final class PopularProducts implements ProductsPopular
{
    private const SALES_SHARE = 0.6;

    private const ADD_WEIGHT = 3;

    public function scores(string $shopId, ?array $among = null): array
    {
        $since = now()->subDays((int) Settings::get('search.popular_days', $shopId))->toDateString();
        $wanted = $among === null ? null : array_flip(array_map('strval', $among));

        // what its shoppers did with its search: the clicks and adds counted per query and day, and the signals by shopper
        $used = [];
        foreach (SearchClick::query()->where('day', '>=', $since)->selectRaw('item, sum(clicks) as opened, sum(added) as added')->groupBy('item')->get() as $row) {
            $id = self::id((string) $row->item);
            $used[$id] = ($used[$id] ?? 0) + (int) $row->opened + self::ADD_WEIGHT * (int) $row->added;
        }
        foreach (SearchSignal::query()->where('day', '>=', $since)->whereIn('kind', ['click', 'add'])->selectRaw('value, kind, sum(visitors) as shoppers')->groupBy('value', 'kind')->get() as $row) {
            $id = self::id((string) $row->value);
            $used[$id] = ($used[$id] ?? 0) + ($row->kind === 'add' ? self::ADD_WEIGHT : 1) * (int) $row->shoppers;
        }

        // what each sold, where the store says it
        $sold = [];
        $products = CatalogProduct::query()->whereNull('removed_at')->when($wanted !== null, fn ($q) => $q->whereIn('external_id', array_keys($wanted)))
            ->select(['external_id', 'payload->sales as sales'])->get();
        $active = [];
        foreach ($products as $product) {
            $id = (string) $product->external_id;
            $active[$id] = true;
            if ((int) $product->getAttribute('sales') > 0) {
                $sold[$id] = (int) $product->getAttribute('sales');
            }
        }

        $topSold = $sold === [] ? 0 : max($sold);
        $topUsed = $used === [] ? 0 : max($used);
        $salesShare = $topSold > 0 ? self::SALES_SHARE : 0.0;
        $out = [];
        foreach (array_keys($active) as $id) {
            $score = ($topSold > 0 ? $salesShare * ($sold[$id] ?? 0) / $topSold : 0.0) + ($topUsed > 0 ? (1 - $salesShare) * ($used[$id] ?? 0) / $topUsed : 0.0);
            if ($score > 0) {
                $out[(string) $id] = round($score, 4);
            }
        }
        arsort($out);

        return $out;
    }

    /** A result's record id as its product's external id ("p:810" is "810"). */
    private static function id(string $item): string
    {
        return str_starts_with($item, 'p:') ? substr($item, 2) : $item;
    }
}
