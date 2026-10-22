<?php

namespace App\Modules\Improvement\Support;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Contracts\DailySummaries;
use App\Modules\Admin\Contracts\DailySummary;
use App\Modules\Improvement\Filament\Merchant\Pages\SiteSuggestions as MerchantSuggestions;
use App\Modules\Improvement\Filament\Operator\Pages\SiteSuggestions as OperatorSuggestions;
use App\Modules\Improvement\Models\ImprovementProposal;
use App\Modules\Improvement\Models\ImprovementReview;
use App\Modules\Tenancy\Models\Shop;
use Carbon\CarbonInterface;

/**
 * The site's daily check, a card a day: what shoppers looked for that the site did not answer, in a
 * few lines of its report, and what it proposed: waiting for the shop's approval, or already on the site.
 */
final class ImprovementDailySummaries implements DailySummaries
{
    public const KIND = 'site';

    /** Lines of the day's report on a card. */
    private const LINES = 6;

    /** Proposals shown in each list of a card. */
    private const ITEMS = 6;

    /** Reports read at most, across shops. */
    private const MAX = 300;

    public function __construct(private readonly TenantContext $tenant) {}

    public function label(): string
    {
        return __('improvement::ui.daily.label');
    }

    public function summaries(?string $shopId, CarbonInterface $since, bool $operator = false): array
    {
        $read = function () use ($shopId, $since, $operator): array {
            $reviews = ImprovementReview::query()->with('proposals:id,review_id,title,status')->where('status', ImprovementReview::REVIEWED)->where('day', '>=', $since->toDateString())
                ->when($shopId !== null, fn ($q) => $q->where('shop_id', $shopId))->orderByDesc('day')->limit(self::MAX)->get();
            $shops = Shop::query()->whereIn('id', $reviews->pluck('shop_id')->unique())->get()->keyBy('id');

            return $reviews->map(function (ImprovementReview $review) use ($shops, $operator): DailySummary {
                $waiting = $review->proposals->where('status', ImprovementProposal::PENDING)->pluck('title')->map(fn ($t): string => (string) $t)->values()->all();
                $done = $review->proposals->whereIn('status', [ImprovementProposal::APPLIED, ImprovementProposal::APPROVED])->pluck('title')->map(fn ($t): string => (string) $t)->values()->all();
                $lines = array_values(array_filter(array_map('trim', explode("\n", Digest::forMerchant($review->digestIn(app()->getLocale())))), fn (string $l): bool => $l !== ''));
                $shop = $shops->get($review->shop_id);

                return new DailySummary(
                    kind: self::KIND,
                    shopId: (string) $review->shop_id,
                    day: $review->day,
                    headline: __('improvement::ui.daily.headline', ['proposed' => (int) $review->proposed, 'applied' => (int) $review->applied]),
                    summary: $lines === [] ? null : implode("\n", array_slice($lines, 0, self::LINES)),
                    lists: array_values(array_filter([
                        $waiting === [] ? null : ['label' => __('improvement::ui.daily.waiting', ['count' => count($waiting)]), 'items' => array_slice($waiting, 0, self::ITEMS), 'tone' => 'warn'],
                        $done === [] ? null : ['label' => __('improvement::ui.daily.done', ['count' => count($done)]), 'items' => array_slice($done, 0, self::ITEMS), 'tone' => 'good'],
                    ])),
                    url: $shop === null ? null : ($operator ? OperatorSuggestions::getUrl(['shop' => $shop->id], panel: 'operator') : MerchantSuggestions::getUrl(panel: 'merchant', tenant: $shop)),
                    tone: $waiting !== [] ? 'warn' : 'good',
                );
            })->values()->all();
        };

        return $shopId === null ? $this->tenant->runUnscoped($read) : $this->tenant->run($shopId, $read);
    }
}
