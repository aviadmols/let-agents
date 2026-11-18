<?php

namespace App\Modules\SearchSubscriptions\Support;

use App\Core\Tenancy\TenantContext;
use App\Modules\Analytics\Models\AnalyticsVisit;
use App\Modules\SearchSubscriptions\Models\SearchSubscription;
use App\Modules\SearchSubscriptions\Models\SearchSubscriptionSend;
use Carbon\CarbonInterface;

/**
 * The store's "Searches people follow": the searches with the most people following them now, and what the emails did in
 * a period (sign-ups confirmed, emails sent, opened, clicked, and the money of the visits they brought, as the store's
 * visits tell it by the emails' campaign tag). Counts only, no model; read inside the shop.
 */
final class FollowedSearches
{
    /** The campaign tag every link of the emails carries (Subscribers::tagged). */
    public const CAMPAIGN = 'search_subscription';

    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @return list<array{query: string, people: int}> the most followed first; each search as most of its followers typed it
     */
    public function top(string $shopId, int $limit): array
    {
        return $this->tenant->run($shopId, function () use ($limit): array {
            $rows = SearchSubscription::query()->where('status', SearchSubscription::ACTIVE)
                ->selectRaw('query_key, count(*) as people')->groupBy('query_key')
                ->orderByDesc('people')->orderBy('query_key')->limit($limit)->get();

            return $rows->map(function ($row): array {
                // the spelling most of them typed; ties by the words themselves, so the same rows always read the same
                $spellings = SearchSubscription::query()->where('status', SearchSubscription::ACTIVE)->where('query_key', $row->query_key)
                    ->pluck('query')->countBy()->sortKeys()->sortDesc();

                return ['query' => Subscribers::cut((string) $spellings->keys()->first(), 60), 'people' => (int) $row->people];
            })->values()->all();
        });
    }

    /**
     * @return array{following: int, signups: int, sent: int, opened: int, clicked: int, revenue: float}
     */
    public function numbers(string $shopId, CarbonInterface $since, CarbonInterface $until): array
    {
        return $this->tenant->run($shopId, function () use ($since, $until): array {
            $sends = SearchSubscriptionSend::query()->where('kind', 'weekly')->whereBetween('sent_at', [$since, $until]);

            return [
                'following' => SearchSubscription::query()->where('status', SearchSubscription::ACTIVE)->distinct()->count('email_hash'),
                'signups' => SearchSubscription::query()->whereNotNull('confirmed_at')->whereBetween('confirmed_at', [$since, $until])->count(),
                'sent' => (clone $sends)->count(),
                'opened' => (clone $sends)->whereNotNull('opened_at')->count(),
                'clicked' => (clone $sends)->whereNotNull('clicked_at')->count(),
                'revenue' => (float) AnalyticsVisit::query()->where('campaign', self::CAMPAIGN)->whereBetween('started_at', [$since, $until])->sum('order_total'),
            ];
        });
    }
}
