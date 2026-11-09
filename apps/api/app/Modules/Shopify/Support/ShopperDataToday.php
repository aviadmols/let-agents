<?php

namespace App\Modules\Shopify\Support;

use App\Modules\Admin\Contracts\TodayFix;
use App\Modules\Admin\Contracts\TodayImprovements;
use App\Modules\Shopify\Models\ShopperDataReport;
use App\Modules\Tenancy\Models\Shop;

/**
 * A shopper asked, through Shopify, for what the store keeps about them (customers/data_request), and no owner has
 * downloaded the file yet: the first thing on Today, above every improvement, until it is taken. Before the redesign
 * this was a notice on the home and in the Inbox; the redesign's Today shows only what modules bring here, and the
 * Inbox left the sidebar (audit, 9.11). Shopify's review triggers this request and looks for it in the app.
 * Never put off or dismissed: a shopper must be answered within 30 days. Asked inside the shop.
 */
final class ShopperDataToday implements TodayImprovements
{
    /** Above any improvement's impact: a request the law answers comes first. */
    private const IMPACT = PHP_INT_MAX;

    public function waiting(Shop $shop): array
    {
        $t = fn (string $key, array $params = []): string => __('shopify::privacy.notice.'.$key, $params);

        return ShopperDataReport::query()
            ->whereNull('downloaded_at')
            ->oldest()
            ->limit(5)
            ->get(['id', 'created_at'])
            ->map(fn (ShopperDataReport $report): TodayFix => new TodayFix(
                key: 'shopify.data_report.'.$report->id,
                chip: TodayFix::REQUIRED,
                count: 0,
                unit: 'times',
                title: $t('title'),
                text: $t('body', ['date' => $report->created_at->format('j.n.Y'), 'due' => $report->created_at->copy()->addDays(30)->format('j.n.Y')]),
                action: $t('download'),
                oneTap: false,
                url: route('shopify.data_report', ['report' => $report->id]),
                impact: self::IMPACT,
                kind: 'info',
            ))
            ->values()
            ->all();
    }

    public function approve(Shop $shop, string $key, ?int $userId, ?string $text = null): ?string
    {
        return null;
    }

    public function dismiss(Shop $shop, string $key, ?int $userId): bool
    {
        return false;
    }

    public function wins(Shop $shop, int $limit): array
    {
        return [];
    }
}
