<?php

namespace App\Modules\Admin\Contracts;

use App\Modules\Tenancy\Models\Shop;

/**
 * The row of numbers at the top of the shop's home, with the change against the period before.
 * One module answers it (Analytics); the home shows nothing there without it.
 */
interface HomeNumbers
{
    /**
     * @return array{currency: string, visitors: array{now: int, before: int}, adds: array{now: int, before: int}, orders: array{now: int, before: int}, revenue: array{now: float, before: float}, all_orders: int}
     */
    public function numbers(Shop $shop, int $days): array;
}
