<?php

namespace App\Core\Usage;

use Illuminate\Support\Facades\DB;

/**
 * What a shop used in a calendar month (UTC), per meter: "answers", "photos". Taking one is a
 * single conditional update, so two requests at once can never both take the last one.
 */
final class MonthlyUsage
{
    private const TABLE = 'monthly_usage';

    /** Takes one of this month's $max; false when the month's are all taken (or $max is 0). */
    public function take(string $shopId, string $meter, int $max): bool
    {
        if ($max <= 0) {
            return false;
        }

        $row = ['shop_id' => $shopId, 'month' => self::month(), 'meter' => $meter];
        DB::table(self::TABLE)->insertOrIgnore($row + ['used' => 0, 'created_at' => now(), 'updated_at' => now()]);

        return DB::table(self::TABLE)->where($row)->where('used', '<', $max)
            ->update(['used' => DB::raw('used + 1'), 'updated_at' => now()]) === 1;
    }

    /** How many were taken this month. */
    public function used(string $shopId, string $meter): int
    {
        return (int) DB::table(self::TABLE)->where(['shop_id' => $shopId, 'month' => self::month(), 'meter' => $meter])->value('used');
    }

    private static function month(): string
    {
        return now('UTC')->format('Y-m');
    }
}
