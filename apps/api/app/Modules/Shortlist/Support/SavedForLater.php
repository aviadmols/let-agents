<?php

namespace App\Modules\Shortlist\Support;

use App\Modules\Admin\Contracts\SavedProducts;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Shortlist\Filament\Operator\Pages\Shortlists;
use App\Modules\Shortlist\Models\ShortlistWishlist;
use App\Modules\Tenancy\Models\Shop;
use Carbon\CarbonInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The wishlists on the Shoppers screen: how many lists changed in the period, the products in them, and the products in
 * the most lists. The export is the wishlist screen's own spreadsheet. Runs inside the shop's TenantContext.
 */
final class SavedForLater implements SavedProducts
{
    /** Lists read for the counts; a store with more reads its latest. */
    private const LISTS_READ = 5000;

    public function numbers(Shop $shop, CarbonInterface $since, CarbonInterface $until): array
    {
        $lists = $this->lists($since, $until);

        return ['lists' => count($lists), 'products' => array_sum(array_map('count', $lists))];
    }

    public function top(Shop $shop, CarbonInterface $since, CarbonInterface $until, int $limit): array
    {
        $saved = [];
        foreach ($this->lists($since, $until) as $items) {
            foreach (array_unique($items) as $id) {
                $saved[$id] = ($saved[$id] ?? 0) + 1;
            }
        }
        // the most saved first; on a tie, the store's id, so the order holds on every database
        uksort($saved, fn (string $a, string $b): int => [$saved[$b], $a] <=> [$saved[$a], $b]);
        $saved = array_slice($saved, 0, $limit, true);
        $products = CatalogProduct::query()->whereIn('external_id', array_map('strval', array_keys($saved)))->get(['external_id', 'title', 'image_url'])->keyBy('external_id');

        $out = [];
        foreach ($saved as $id => $n) {
            $product = $products->get((string) $id);
            if ($product !== null) {
                $out[] = ['id' => (string) $id, 'title' => (string) $product->title, 'picture' => $product->image_url, 'saved' => $n];
            }
        }

        return $out;
    }

    public function export(Shop $shop): StreamedResponse
    {
        $page = new Shortlists;
        $page->shop = (string) $shop->id;

        return $page->export();
    }

    /** @return list<list<string>> each list's products changed in the period */
    private function lists(CarbonInterface $since, CarbonInterface $until): array
    {
        return ShortlistWishlist::query()->where('updated_at', '>=', $since)->where('updated_at', '<', $until)
            ->latest('updated_at')->limit(self::LISTS_READ)->get(['id', 'items'])
            ->map(fn (ShortlistWishlist $list): array => array_values(array_map('strval', (array) $list->items)))
            ->filter(fn (array $items): bool => $items !== [])->values()->all();
    }
}
