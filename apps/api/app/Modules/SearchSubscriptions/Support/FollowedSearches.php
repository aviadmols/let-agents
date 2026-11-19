<?php

namespace App\Modules\SearchSubscriptions\Support;

use App\Core\Tenancy\TenantContext;
use App\Modules\Analytics\Models\AnalyticsVisit;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\SearchSubscriptions\Models\SearchSubscription;
use App\Modules\SearchSubscriptions\Models\SearchSubscriptionSend;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

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
     * When the store's subscriptions last changed (a sign-up, a confirmation, a stop from any door): what the page's
     * counts are kept under, so a sign-up shows at once (Rocks, 19.11: confirmed, and the page said 0 for five minutes).
     */
    public function stamp(string $shopId): string
    {
        return (string) $this->tenant->run($shopId, fn () => SearchSubscription::query()->max('updated_at'));
    }

    /**
     * What else the page's per-sign-up lines depend on: the catalogue's last change (a new product, one out of stock)
     * and the weekly emails' (one planned, mailed or taken back). Kept beside stamp() for upcoming()'s cache.
     */
    public function changes(string $shopId): string
    {
        return $this->tenant->run($shopId, function (): string {
            $catalogue = CatalogProduct::query()->toBase()->selectRaw('count(*) as n, max(updated_at) as m')->first();
            $sends = SearchSubscriptionSend::query()->where('kind', 'weekly')->toBase()->selectRaw('count(*) as n, max(updated_at) as m')->first();

            return md5(($catalogue->n ?? 0).'|'.($catalogue->m ?? '').'|'.($sends->n ?? 0).'|'.($sends->m ?? ''));
        });
    }

    /**
     * The latest sign-ups, for the store's own page: who (the address the shopper gave this store), to which search,
     * since when and where it stands. Newest first.
     *
     * @return list<array{id: string, hash: string, email: string, query: string, status: string, at: CarbonInterface|null}>
     */
    public function recent(string $shopId, int $limit): array
    {
        return $this->tenant->run($shopId, fn (): array => SearchSubscription::query()->latest('created_at')->orderByDesc('id')->limit($limit)
            ->get(['id', 'email', 'email_hash', 'query', 'status', 'created_at', 'confirmed_at'])
            ->map(fn (SearchSubscription $s): array => ['id' => (string) $s->id, 'hash' => (string) $s->email_hash, 'email' => (string) $s->email,
                'query' => Subscribers::cut((string) $s->query, 60), 'status' => (string) $s->status, 'at' => $s->confirmed_at ?? $s->created_at])
            ->all());
    }

    /**
     * For the sign-ups shown: how many products each active search has for its address's next email, by the weekly
     * email's own rule (NextEmail: new since confirmed, in stock, matching, not sent before, the email's cap shared among
     * the address's searches), and when each address was last emailed. Only these addresses; each search runs once.
     *
     * @param  list<string>  $hashes
     * @return array{next: array<string, int>, last: array<string, string>} subscription id => products; address => last weekly email
     */
    public function upcoming(string $shopId, array $hashes): array
    {
        $hashes = array_values(array_unique($hashes));
        if ($hashes === []) {
            return ['next' => [], 'last' => []];
        }

        return $this->tenant->run($shopId, function () use ($shopId, $hashes): array {
            $active = SearchSubscription::query()->where('status', SearchSubscription::ACTIVE)->whereIn('email_hash', $hashes)
                ->orderBy('email_hash')->orderBy('confirmed_at')->orderBy('id')->get(['id', 'email_hash', 'query', 'query_key', 'confirmed_at']);
            $earliest = $active->filter(fn (SearchSubscription $s): bool => $s->confirmed_at !== null)->map(fn (SearchSubscription $s): int => $s->confirmed_at->getTimestamp())->min();
            $since = $earliest !== null ? Carbon::createFromTimestamp($earliest, (string) config('app.timezone')) : null;

            $next = [];
            $picks = app(NextEmail::class)->start($shopId, $since, $active->pluck('query_key')->unique()->values()->all())
                ->pick($active->groupBy('email_hash')->all());
            foreach ($picks as $bySubscription) {
                foreach ($bySubscription as $id => $products) {
                    $next[(string) $id] = count($products);
                }
            }

            $last = SearchSubscriptionSend::query()->where('kind', 'weekly')->whereIn('email_hash', $hashes)->toBase()
                ->selectRaw('email_hash, max(sent_at) as last')->groupBy('email_hash')->pluck('last', 'email_hash')
                ->map(fn ($at): string => (string) $at)->all();

            return ['next' => $next, 'last' => $last];
        });
    }

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
