<?php

namespace App\Modules\Shortlist\Support;

use App\Modules\Admin\Contracts\ReportRow;
use App\Modules\Admin\Contracts\ShopReport;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Shortlist\Models\ShortlistWishlist;
use App\Modules\Tenancy\Models\Shop;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The wishlist's part of the shop's analytics screen: lists made in the period, items saved, lists kept
 * by a verified phone, and the products saved most. Counts only, never a phone number.
 */
final class ShortlistReport implements ShopReport
{
    /** Lists read for "saved most": the newest, enough for a store's month. */
    private const LISTS_READ = 5000;

    public function figures(Shop $shop, CarbonInterface $since, CarbonInterface $until): array
    {
        $made = ShortlistWishlist::query()->where('created_at', '>=', $since)->where('created_at', '<', $until);
        $saved = 0;

        foreach ($this->touched($since, $until) as $list) {
            $saved += count((array) $list->items);
        }

        return ['wishlist' => [
            'lists' => (clone $made)->count(),
            'saved' => $saved,
            'phone' => ShortlistWishlist::query()->where('phone_verified_at', '>=', $since)->where('phone_verified_at', '<', $until)->count(),
        ]];
    }

    public function lists(): array
    {
        return ['wish'];
    }

    public function rows(Shop $shop, string $list, CarbonInterface $since, CarbonInterface $until, int $limit, string $search = '', string $sort = 'value'): array
    {
        $count = [];

        foreach ($this->touched($since, $until) as $wishlist) {
            foreach (array_unique(array_map('strval', (array) $wishlist->items)) as $id) {
                $count[$id] = ($count[$id] ?? 0) + 1;
            }
        }

        $products = CatalogProduct::query()->whereIn('external_id', array_keys($count))->get(['external_id', 'title', 'image_url', 'price'])->keyBy('external_id');
        $rows = [];

        foreach ($count as $id => $n) {
            $product = $products[$id] ?? null;
            $title = trim((string) ($product?->title ?? ''));

            if ($title === '' || ($search !== '' && mb_stripos($title, trim($search)) === false)) {
                continue;
            }

            $rows[] = new ReportRow(key: (string) $id, title: $title, value: $n, thumb: $product?->image_url);
        }

        usort($rows, fn (ReportRow $a, ReportRow $b): int => $sort === 'name' ? strcmp($a->title, $b->title) : [$b->value, $a->title] <=> [$a->value, $b->title]);

        return ['rows' => array_slice($rows, 0, $limit), 'total' => count($rows)];
    }

    public function act(Shop $shop, string $list, string $key, string $action, string $value = ''): ?string
    {
        return null;
    }

    /** @return Collection<int, ShortlistWishlist> lists touched in the period */
    private function touched(CarbonInterface $since, CarbonInterface $until)
    {
        return ShortlistWishlist::query()->where('updated_at', '>=', $since)->where('updated_at', '<', $until)
            ->latest('updated_at')->limit(self::LISTS_READ)->get(['id', 'items']);
    }
}
