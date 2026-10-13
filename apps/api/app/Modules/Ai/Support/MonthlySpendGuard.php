<?php

namespace App\Modules\Ai\Support;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\SpendCapReached;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Runs\Models\Run;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Adds up the cost runs recorded since the start of the calendar month (UTC): for every shop
 * against the platform's cap, and for the shop the call is made in against that shop's allowance.
 */
final class MonthlySpendGuard implements SpendGuard
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function assertCanSpend(float $estimatedUsd, bool $forShopper = false): void
    {
        $estimate = max(0.0, $estimatedUsd);
        $spent = $this->spentThisMonth();
        $cap = $this->cap();

        if ($spent + $estimate > $cap) {
            throw new SpendCapReached($spent, $estimate, $cap);
        }

        $shopId = $this->tenant->id();

        if ($shopId === null || ($forShopper && ! Settings::get('ai.shop_cap_stops_shoppers', $shopId))) {
            return;
        }

        $shopSpent = $this->spentThisMonthFor($shopId);
        $shopCap = $this->capFor($shopId);

        if ($shopSpent + $estimate > $shopCap) {
            throw new SpendCapReached($shopSpent, $estimate, $shopCap, $shopId);
        }
    }

    public function spentThisMonth(): float
    {
        return (float) $this->month()->sum('cost_usd');
    }

    public function cap(): float
    {
        return (float) Settings::get('ai.monthly_spend_cap_usd');
    }

    public function spentThisMonthFor(string $shopId): float
    {
        return (float) $this->month()->where('shop_id', $shopId)->sum('cost_usd');
    }

    public function capFor(string $shopId): float
    {
        return (float) Settings::get('ai.shop_monthly_cap_usd', $shopId);
    }

    /**
     * What every shop spent this month, in one query, for the screens that list them.
     *
     * @return array<string, array{spent: float, calls: int}>
     */
    public static function byShop(): array
    {
        return Run::query()
            ->where('created_at', '>=', now()->utc()->startOfMonth())
            ->whereNotNull('cost_usd')
            ->whereNotNull('shop_id')
            ->select('shop_id', DB::raw('sum(cost_usd) as spent'), DB::raw('count(*) as calls'))
            ->groupBy('shop_id')
            ->get()
            ->mapWithKeys(fn ($row): array => [(string) $row->shop_id => ['spent' => round((float) $row->spent, 4), 'calls' => (int) $row->calls]])
            ->all();
    }

    /** @return Builder<Run> */
    private function month(): Builder
    {
        return Run::query()
            ->where('created_at', '>=', now()->utc()->startOfMonth())
            ->whereNotNull('cost_usd');
    }
}
