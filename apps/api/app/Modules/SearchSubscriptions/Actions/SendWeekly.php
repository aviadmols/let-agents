<?php

namespace App\Modules\SearchSubscriptions\Actions;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Connections\Enums\ConnectionStatus;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\SearchSubscriptions\Jobs\DeliverWeeklyEmails;
use App\Modules\SearchSubscriptions\Jobs\SendWeeklyForShop;
use App\Modules\SearchSubscriptions\Models\SearchSubscription;
use App\Modules\SearchSubscriptions\Models\SearchSubscriptionProduct;
use App\Modules\SearchSubscriptions\Models\SearchSubscriptionSend;
use App\Modules\SearchSubscriptions\Support\Availability;
use App\Modules\SearchSubscriptions\Support\NextEmail;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The weekly email of Search Subscriptions, for the stores whose day and hour it is in their own time (the owner picks
 * both; each hour the scheduler calls dispatchDue(), which hands every due store to a job of its own). One email at most
 * a week to an address in a store, all its searches together, holding what NextEmail picks; nothing new: no email.
 *
 * Built for a store with thousands of addresses, and several stores in one hour:
 * - the addresses are walked a slice at a time (CHUNK), and every check of a slice is one query for all of it;
 * - each distinct search runs once for everyone who follows it, and not at all when the store added nothing new;
 * - the emails are only planned here (a send row and its products, a slice in one insert) and mailed by queued jobs,
 *   up to BATCH an email batch, at the pace the mail service allows (DeliverWeeklyEmails);
 * - a run that repeats (a retried job, the hour twice) plans nothing twice: an address emailed in the last six days is
 *   skipped, and the database holds one weekly email per address and store week (period).
 *
 * An address whose last search_subscriptions.unopened_stop emails were neither opened nor clicked is stopped instead.
 * A subscription past its end is stopped. A store no longer connected (Lets removed) is skipped: ForgetRemovedStores
 * deletes its subscriptions after a while.
 */
final class SendWeekly
{
    /** The addresses planned together. */
    public const CHUNK = 500;

    /** The emails in one delivery: the mail service's batch. */
    public const BATCH = 100;

    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * For the scheduler: a job for each store whose day and hour it is now, so one store's run never holds the
     * scheduler or another store.
     *
     * @return int the stores handed on
     */
    public function dispatchDue(?Carbon $now = null): int
    {
        $now ??= now();
        $this->resumeStalled($now);
        $count = 0;
        foreach ($this->shops($now, null) as $shop) {
            SendWeeklyForShop::dispatch((string) $shop->id, $now->getTimestamp(), self::period($shop, $now));
            $count++;
        }

        return $count;
    }

    /**
     * Weekly emails planned but never mailed and no longer waiting in any job (a planning job cut off between writing
     * and queueing; every delivery job ends within DeliverWeeklyEmails::retryUntil and takes back what failed): queued
     * again, late but once, while they are still this week's. Marked as handed on, so the next hour leaves them be.
     */
    private function resumeStalled(Carbon $now): void
    {
        $stalled = $this->tenant->runUnscoped(fn () => SearchSubscriptionSend::query()->where('kind', 'weekly')->whereNotNull('period')->whereNull('mailed_at')
            ->where('created_at', '>', $now->copy()->subDays(2))->where('updated_at', '<', $now->copy()->subHours(7))
            ->orderBy('id')->limit(10 * self::BATCH * self::BATCH)->get(['id', 'shop_id']));
        foreach ($stalled->groupBy('shop_id') as $shopId => $sends) {
            foreach ($sends->pluck('id')->map(fn ($id): string => (string) $id)->chunk(self::BATCH) as $batch) {
                $this->tenant->runUnscoped(fn () => SearchSubscriptionSend::query()->whereIn('id', $batch->all())->update(['updated_at' => $now]));
                DeliverWeeklyEmails::dispatch((string) $shopId, $batch->values()->all());
            }
        }
    }

    /**
     * Plan the weekly emails now, for the stores due now (or for $onlyShop whatever its day), and queue them.
     *
     * @return int the emails planned
     */
    public function handle(?Carbon $now = null, ?string $onlyShop = null): int
    {
        $now ??= now();
        $planned = 0;
        foreach ($this->shops($now, $onlyShop) as $shop) {
            $planned += $this->forShop($shop, $now);
        }

        return $planned;
    }

    /** One store's run (a job's): nothing when the feature is off or Lets was removed since. */
    public function forShop(Shop $shop, Carbon $now): int
    {
        $shopId = (string) $shop->id;
        if (! Availability::on($shopId) || ! $this->connected($shopId)) {
            return 0;
        }
        try {
            return $this->tenant->run($shopId, fn (): int => $this->plan($shop, $now));
        } catch (Throwable $e) {
            Log::warning('search subscriptions: a store\'s weekly emails failed', ['shop' => $shopId, 'error' => $e->getMessage()]);

            return 0;
        }
    }

    /** Whether it is the store's day and hour in its own time. */
    public static function due(Shop $shop, Carbon $now): bool
    {
        $local = $now->copy()->setTimezone(self::timezone($shop));
        $day = (string) Settings::get('search_subscriptions.send_day', $shop->id);
        $hour = (int) Settings::get('search_subscriptions.send_hour', $shop->id);

        return strtolower($local->englishDayOfWeek) === $day && $local->hour === $hour;
    }

    /** The store's week a run belongs to, in its own time (2026-W47). */
    public static function period(Shop $shop, Carbon $now): string
    {
        return $now->copy()->setTimezone(self::timezone($shop))->format('o-\WW');
    }

    private static function timezone(Shop $shop): string
    {
        return (string) ($shop->timezone ?: 'Asia/Jerusalem');
    }

    /** @return list<Shop> the stores with active subscriptions, those due now unless one is named */
    private function shops(Carbon $now, ?string $onlyShop): array
    {
        $ids = $this->tenant->runUnscoped(fn () => SearchSubscription::query()->where('status', SearchSubscription::ACTIVE)
            ->when($onlyShop !== null, fn ($q) => $q->where('shop_id', $onlyShop))->distinct()->pluck('shop_id')->all());
        if ($ids === []) {
            return [];
        }

        return Shop::query()->whereIn('id', $ids)->orderBy('id')->get()
            ->filter(fn (Shop $shop): bool => $onlyShop !== null || self::due($shop, $now))->values()->all();
    }

    private function connected(string $shopId): bool
    {
        return $this->tenant->runUnscoped(fn () => StoreConnection::query()->where('shop_id', $shopId)->where('status', '!=', ConnectionStatus::Failed)->exists());
    }

    private function plan(Shop $shop, Carbon $now): int
    {
        SearchSubscription::query()->where('status', SearchSubscription::ACTIVE)->whereNotNull('expires_at')->where('expires_at', '<', $now)
            ->update(['status' => SearchSubscription::STOPPED, 'stop_reason' => 'expired', 'stopped_at' => $now]);

        $since = SearchSubscription::query()->where('status', SearchSubscription::ACTIVE)->min('confirmed_at');
        $next = app(NextEmail::class)->start((string) $shop->id, $since !== null ? Carbon::parse((string) $since) : null);
        $period = self::period($shop, $now);

        $planned = 0;
        $after = '';
        do {
            $hashes = SearchSubscription::query()->where('status', SearchSubscription::ACTIVE)->where('email_hash', '>', $after)
                ->distinct()->orderBy('email_hash')->limit(self::CHUNK)->pluck('email_hash')->map(fn ($h): string => (string) $h)->all();
            if ($hashes === []) {
                break;
            }
            $after = (string) end($hashes);
            $planned += $this->planSlice($shop, $hashes, $next, $now, $period);
        } while (count($hashes) === self::CHUNK);

        return $planned;
    }

    /**
     * One slice of addresses: skip those emailed in the last six days, stop those that stopped reading, pick the rest's
     * products, write their emails, queue them.
     *
     * @param  list<string>  $hashes
     */
    private function planSlice(Shop $shop, array $hashes, NextEmail $next, Carbon $now, string $period): int
    {
        $recent = SearchSubscriptionSend::query()->where('kind', 'weekly')->whereIn('email_hash', $hashes)->where('sent_at', '>', $now->copy()->subDays(6))
            ->distinct()->pluck('email_hash')->flip()->all();
        $hashes = array_values(array_filter($hashes, fn (string $h): bool => ! isset($recent[$h])));
        if ($hashes === []) {
            return 0;
        }

        $unopened = $this->unopened($hashes);
        if ($unopened !== []) {
            SearchSubscription::query()->where('status', SearchSubscription::ACTIVE)->whereIn('email_hash', $unopened)
                ->update(['status' => SearchSubscription::STOPPED, 'stop_reason' => 'unopened', 'stopped_at' => $now]);
            $hashes = array_values(array_diff($hashes, $unopened));
            if ($hashes === []) {
                return 0;
            }
        }

        $byAddress = SearchSubscription::query()->where('status', SearchSubscription::ACTIVE)->whereIn('email_hash', $hashes)
            ->orderBy('email_hash')->orderBy('confirmed_at')->orderBy('id')
            ->get(['id', 'shop_id', 'email_hash', 'query', 'query_key', 'confirmed_at'])->groupBy('email_hash')->all();
        $picks = $next->pick($byAddress);
        if ($picks === []) {
            return 0;
        }

        $sends = [];
        $products = [];
        foreach ($picks as $hash => $bySubscription) {
            $id = strtolower((string) Str::ulid());
            $queries = [];
            foreach ($byAddress[$hash] as $subscription) {
                $picked = $bySubscription[(string) $subscription->id] ?? [];
                if ($picked !== []) {
                    $queries[] = (string) $subscription->query;
                }
                foreach ($picked as $productId) {
                    $products[$id][] = ['shop_id' => $shop->id, 'email_hash' => $hash, 'product_id' => $productId,
                        'subscription_id' => $subscription->id, 'send_id' => $id, 'sent_at' => $now];
                }
            }
            $sends[] = ['id' => $id, 'shop_id' => $shop->id, 'email_hash' => $hash, 'kind' => 'weekly', 'period' => $period,
                'queries' => json_encode($queries, JSON_UNESCAPED_UNICODE), 'products' => count($products[$id] ?? []),
                'sent_at' => $now, 'created_at' => $now, 'updated_at' => $now];
        }

        // one weekly email per address and store week, held by the database: a run that repeats writes nothing twice
        $written = DB::transaction(function () use ($sends, $products): array {
            foreach (array_chunk($sends, 250) as $rows) {
                SearchSubscriptionSend::query()->insertOrIgnore($rows);
            }
            $written = SearchSubscriptionSend::query()->whereIn('id', array_column($sends, 'id'))->pluck('id')->map(fn ($id): string => (string) $id)->all();
            $rows = array_merge(...array_values(array_intersect_key($products, array_flip($written))));
            foreach (array_chunk($rows, 500) as $slice) {
                SearchSubscriptionProduct::query()->insertOrIgnore($slice);
            }

            return $written;
        });

        foreach (array_chunk($written, self::BATCH) as $batch) {
            DeliverWeeklyEmails::dispatch((string) $shop->id, $batch);
        }

        return count($written);
    }

    /**
     * The addresses whose last weekly emails, as many as stop them, were neither opened nor clicked: that many emails
     * since the last one opened or clicked (or ever). One query for the slice.
     *
     * @param  list<string>  $hashes
     * @return list<string>
     */
    private function unopened(array $hashes): array
    {
        $limit = (int) Settings::get('search_subscriptions.unopened_stop');

        return SearchSubscriptionSend::query()->where('kind', 'weekly')->whereIn('email_hash', $hashes)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('search_subscription_sends as e')
                ->whereColumn('e.shop_id', 'search_subscription_sends.shop_id')->whereColumn('e.email_hash', 'search_subscription_sends.email_hash')
                ->where('e.kind', 'weekly')->whereColumn('e.sent_at', '>=', 'search_subscription_sends.sent_at')
                ->where(fn ($w) => $w->whereNotNull('e.opened_at')->orWhereNotNull('e.clicked_at')))
            ->groupBy('email_hash')->havingRaw('count(*) >= ?', [$limit])
            ->pluck('email_hash')->map(fn ($h): string => (string) $h)->all();
    }

    /** The price as the email writes it: the currency's sign and the amount, decimals only when there are any. */
    public static function price(CatalogProduct $product): string
    {
        if ($product->price === null) {
            return '';
        }
        $amount = (float) $product->price;
        $text = number_format($amount, floor($amount) === $amount ? 0 : 2);
        $code = strtoupper((string) ($product->currency ?: 'ILS'));
        $sign = ['ILS' => '₪', 'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'AUD' => 'A$', 'CAD' => 'C$'][$code] ?? $code.' ';

        return $sign.$text;
    }
}
