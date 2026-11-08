<?php

namespace App\Modules\Admin\Filament\Merchant\Pages;

use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use UnitEnum;

/**
 * Settings · Plan and billing, as the designer's redesign lays it out (Tomer, 11.2026), and the page Settings opens on:
 * the plan now (its trial, what is used of the month's allowance), the plans side by side with the way to each, what is
 * in each plan and how the store is billed. The connection page's own plans and allowance, on a page of their own: the
 * same switch (Shopify's billing, or asking the team) and the same counts.
 */
final class PlanSettings extends ConnectionSettings
{
    public static string $sideGroup = 'store';

    /** Settings opens here (MerchantNavigation). */
    public static bool $opensSection = true;

    /** Where every plan is compared in full. */
    public const PRICING_URL = 'https://lets.co.il/pricing/';

    protected static string|UnitEnum|null $navigationGroup = 'settings';

    protected static ?int $navigationSort = 115;

    protected static ?string $slug = 'settings/plan';

    protected string $view = 'admin::merchant.settings.plan';

    public static function getNavigationLabel(): string
    {
        return __('admin::plan.nav');
    }

    public function getTitle(): string
    {
        return __('admin::plan.settings');
    }

    public function getSubheading(): ?string
    {
        return null;
    }

    public function getHeader(): ?View
    {
        return view('admin::merchant.screen-head', ['title' => $this->getTitle(), 'lede' => __('admin::plan.lede')]);
    }

    /**
     * The plan now: its name, the trial's days when it is on one (the platform's or the team's), and the line under it.
     *
     * @return array{name: string, trial: int|null, ending: string|null, waiting: bool, free: bool, renews: string|null}
     */
    public function now(): array
    {
        $shop = $this->shop();
        $facts = $this->facts($shop);
        $trialEnds = ($facts['trial_ends'] ?? null) instanceof CarbonInterface ? $facts['trial_ends']
            : ($shop->trial_ends_at !== null && $shop->trial_ends_at->isFuture() ? $shop->trial_ends_at : null);
        $until = ! $shop->leaving() ? $shop->planRunsUntil() : null;

        return [
            'name' => is_string($facts['plan'] ?? null) && $facts['plan'] !== '' ? $facts['plan'] : ($shop->currentPlan()?->name ?? __('admin::shop_settings.connection.plan_default')),
            'trial' => $trialEnds === null ? null : max(1, (int) ceil(now()->diffInHours($trialEnds, false) / 24)),
            'ending' => $until?->format('j.n.Y'),
            'waiting' => ($facts['plan_waiting'] ?? false) === true,
            'free' => ($facts['plan_free'] ?? false) === true,
            'renews' => ($facts['renews'] ?? null) instanceof CarbonInterface ? $facts['renews']->format('j.n.Y') : null,
        ];
    }
}
