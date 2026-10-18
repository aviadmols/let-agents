<?php

namespace App\Modules\Analytics\Filament\Merchant\Pages;

use App\Core\Tenancy\LocksShopToPanelTenant;
use App\Modules\Analytics\Filament\Operator\Pages\ShopAnalytics as OperatorShopAnalytics;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;
use UnitEnum;

/**
 * The shop's own report. The same screen the operator uses, with the shop fixed to the one in the
 * address: a merchant has no shop to pick and no way to name another.
 */
final class ShopAnalytics extends OperatorShopAnalytics
{
    use LocksShopToPanelTenant;

    protected static string|UnitEnum|null $navigationGroup = 'analytics';

    protected static ?int $navigationSort = 10;

    /** A shop owner sees what happened in the store, never what it cost to run the agents. */
    public function showsCosts(): bool
    {
        return false;
    }

    /**
     * The other analytics screens of the shop, as tabs above the report: the site search day by day
     * and every search with its visit. They left the menu; the analytics are one place.
     *
     * @return list<array{label: string, url: string, current: bool}>
     */
    public function tabs(): array
    {
        $tenant = Filament::getTenant();
        $tabs = [['label' => __('analytics::analytics.tabs.overview'), 'url' => self::getUrl(), 'current' => true]];

        foreach (['analytics.search' => 'search', 'analytics.search-log' => 'search_log'] as $page => $label) {
            $route = "filament.merchant.pages.{$page}";

            if ($tenant !== null && Route::has($route)) {
                $tabs[] = ['label' => __("analytics::analytics.tabs.{$label}"), 'url' => route($route, ['tenant' => $tenant]), 'current' => false];
            }
        }

        return $tabs;
    }
}
