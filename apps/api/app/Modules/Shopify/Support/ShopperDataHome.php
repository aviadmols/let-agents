<?php

namespace App\Modules\Shopify\Support;

use App\Modules\Admin\Contracts\HomeSource;
use App\Modules\Admin\Contracts\ShopNotice;
use App\Modules\Shopify\Models\ShopperDataReport;
use App\Modules\Tenancy\Models\Shop;
use Carbon\CarbonInterface;

/**
 * A shopper asked for what the store keeps about them: a thing to do in the notices until an owner downloads it to
 * send on, then a line that it was taken, for the days a reply is due in (DAYS). Nothing about the shopper is in
 * the notice; the download is for the owners. Asked inside the shop.
 */
final class ShopperDataHome implements HomeSource
{
    /** A shopper is answered within 30 days; the notice stays as long. */
    private const DAYS = 30;

    public function statuses(Shop $shop): array
    {
        return [];
    }

    public function notices(Shop $shop): array
    {
        $t = fn (string $key, array $params = []): string => __('shopify::privacy.notice.'.$key, $params);

        return ShopperDataReport::query()
            ->where(fn ($q) => $q->whereNull('downloaded_at')->orWhere('created_at', '>=', now()->subDays(self::DAYS)))
            ->latest()
            ->limit(20)
            ->get(['id', 'downloaded_at', 'created_at'])
            ->map(fn (ShopperDataReport $report): ShopNotice => new ShopNotice(
                key: 'shopify.data_report.'.$report->id,
                kind: $report->downloaded_at === null ? ShopNotice::TODO : ShopNotice::REPORT,
                tone: $report->downloaded_at === null ? 'warn' : 'good',
                title: $t('title'),
                body: $report->downloaded_at === null
                    ? $t('body', ['date' => $report->created_at->format('j.n.Y'), 'due' => $report->created_at->copy()->addDays(self::DAYS)->format('j.n.Y')])
                    : $t('taken', ['date' => $report->downloaded_at->format('j.n.Y')]),
                source: $t('source'),
                at: $report->created_at,
                url: route('shopify.data_report', ['report' => $report->id]),
                action: $t('download'),
                weight: $report->downloaded_at === null ? 90 : 10,
            ))
            ->values()
            ->all();
    }

    public function figures(Shop $shop, CarbonInterface $since, CarbonInterface $until, CarbonInterface $before): array
    {
        return [];
    }
}
