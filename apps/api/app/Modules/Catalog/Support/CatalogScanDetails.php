<?php

namespace App\Modules\Catalog\Support;

use App\Modules\Admin\Contracts\ScanDetails;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Support\Facades\DB;

/** The catalogue step of the scan: the products read, by product type, and the new ones day by day. */
final class CatalogScanDetails implements ScanDetails
{
    private const TYPES = 12;

    private const DAYS = 7;

    public function scanDetails(Shop $shop): array
    {
        $active = CatalogProduct::query()->active();
        $types = [];

        foreach ((clone $active)->pluck('payload') as $payload) {
            $type = trim((string) (((array) $payload)['product_type'] ?? ''));
            $types[$type === '' ? __('catalog::scan.no_type') : $type] = ($types[$type === '' ? __('catalog::scan.no_type') : $type] ?? 0) + 1;
        }

        arsort($types);

        $days = CatalogProduct::query()
            ->where('created_at', '>=', now()->subDays(self::DAYS - 1)->startOfDay())
            ->select(DB::raw('DATE(created_at) as day'), DB::raw('count(*) as n'))
            ->groupBy('day')
            ->pluck('n', 'day');

        $byDay = [];
        for ($i = 0; $i < self::DAYS; $i++) {
            $day = now()->subDays($i);
            $byDay[] = [$day->locale(app()->getLocale())->translatedFormat(__('catalog::scan.day_format')), __('catalog::scan.new_count', ['count' => (int) ($days[$day->toDateString()] ?? 0)])];
        }

        return ['catalog' => [
            [
                'title' => __('catalog::scan.types', ['count' => number_format((clone $active)->count())]),
                'rows' => array_map(fn (string $type, int $n): array => [$type, __('catalog::scan.products', ['count' => number_format($n)])], array_keys(array_slice($types, 0, self::TYPES, true)), array_slice($types, 0, self::TYPES, true)),
            ],
            ['title' => __('catalog::scan.new_by_day'), 'rows' => $byDay],
        ]];
    }
}
