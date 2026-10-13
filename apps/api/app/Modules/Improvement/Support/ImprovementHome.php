<?php

namespace App\Modules\Improvement\Support;

use App\Modules\Admin\Contracts\HomeSource;
use App\Modules\Admin\Contracts\ShopNotice;
use App\Modules\Improvement\Filament\Merchant\Pages\SiteSuggestions;
use App\Modules\Improvement\Models\ImprovementProposal;
use App\Modules\Improvement\Models\ImprovementReview;
use App\Modules\Tenancy\Models\Shop;
use Carbon\CarbonInterface;

/**
 * The site suggestions on the shop's home: the ones waiting for the manager's yes, and the night's
 * report as a line in the notices. Asked inside the shop.
 */
final class ImprovementHome implements HomeSource
{
    public function statuses(Shop $shop): array
    {
        return [];
    }

    public function notices(Shop $shop): array
    {
        $url = SiteSuggestions::getUrl(panel: 'merchant', tenant: $shop);
        $notices = [];
        $pending = ImprovementProposal::query()->where('status', ImprovementProposal::PENDING)->latest()->get(['id', 'kind', 'title', 'created_at']);

        if ($pending->isNotEmpty()) {
            $notices[] = new ShopNotice(
                key: 'improvement.pending',
                kind: ShopNotice::TODO,
                tone: 'info',
                title: trans_choice('improvement::home.pending_title', $pending->count(), ['count' => $pending->count()]),
                body: __('improvement::home.pending_body', ['first' => (string) $pending->first()->title]),
                source: __('improvement::home.source'),
                at: $pending->first()->created_at,
                url: $url,
                action: __('improvement::home.review'),
                weight: 80,
            );
        }

        $review = ImprovementReview::query()->latest('day')->first();

        if ($review !== null) {
            $notices[] = new ShopNotice(
                key: 'improvement.review.'.$review->day->toDateString(),
                kind: ShopNotice::REPORT,
                tone: 'good',
                title: __('improvement::home.review_title', ['date' => $review->day->format('d.m')]),
                body: __('improvement::home.review_body', ['proposed' => (int) $review->proposed, 'applied' => (int) $review->applied]),
                source: __('improvement::home.source'),
                at: $review->day->copy()->setTime(5, 0),
                url: $url,
                weight: 10,
            );
        }

        return $notices;
    }

    public function figures(Shop $shop, CarbonInterface $since, CarbonInterface $until, CarbonInterface $before): array
    {
        return [];
    }
}
