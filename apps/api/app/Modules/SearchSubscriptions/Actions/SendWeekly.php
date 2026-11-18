<?php

namespace App\Modules\SearchSubscriptions\Actions;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Connections\Enums\ConnectionStatus;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Search\Contracts\ProductsForQuery;
use App\Modules\SearchSubscriptions\Mail\WeeklyNewArrivalsMail;
use App\Modules\SearchSubscriptions\Models\SearchSubscription;
use App\Modules\SearchSubscriptions\Models\SearchSubscriptionProduct;
use App\Modules\SearchSubscriptions\Models\SearchSubscriptionSend;
use App\Modules\SearchSubscriptions\Support\Availability;
use App\Modules\SearchSubscriptions\Support\Subscribers;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The weekly email of Search Subscriptions, for the stores whose day and hour it is in their own time (each hour,
 * from the scheduler). One email at most a week to an address in a store, all its searches together, and only:
 *
 * - products added to the store after the search was confirmed (a product back in stock is not new), none from a bulk
 *   import (search_subscriptions.bulk_import_skip products in one hour), and in stock now;
 * - found by the store's own search by spelling (ProductsForQuery): strict, a product missed rather than one unrelated;
 * - never one sent to the address before, at most search_subscriptions.products_per_email, the best of each search in
 *   turn. Nothing new: no email that week.
 *
 * An address whose last search_subscriptions.unopened_stop emails were neither opened nor clicked is stopped instead.
 * A subscription past its end is stopped. A store no longer connected (Lets removed) is skipped: ForgetRemovedStores
 * deletes its subscriptions after a while.
 */
final class SendWeekly
{
    public function __construct(private readonly TenantContext $tenant, private readonly ProductsForQuery $products) {}

    /** @return int the emails sent */
    public function handle(?Carbon $now = null, ?string $onlyShop = null): int
    {
        $now ??= now();
        $sent = 0;
        $shopIds = $this->tenant->runUnscoped(fn () => SearchSubscription::query()->where('status', SearchSubscription::ACTIVE)
            ->when($onlyShop !== null, fn ($q) => $q->where('shop_id', $onlyShop))->distinct()->pluck('shop_id')->all());

        foreach ($shopIds as $shopId) {
            $shop = Shop::query()->find($shopId);
            if ($shop === null || ! Availability::on((string) $shopId) || ($onlyShop === null && ! self::due($shop, $now))) {
                continue;
            }
            $connected = $this->tenant->runUnscoped(fn () => StoreConnection::query()->where('shop_id', $shopId)->where('status', '!=', ConnectionStatus::Failed)->exists());
            if (! $connected) {
                continue;
            }
            try {
                $sent += $this->tenant->run((string) $shopId, fn (): int => $this->forShop($shop, $now));
            } catch (Throwable $e) {
                Log::warning('search subscriptions: a store\'s weekly emails failed', ['shop' => $shopId, 'error' => $e->getMessage()]);
            }
        }

        return $sent;
    }

    /** Whether it is the store's day and hour in its own time. */
    public static function due(Shop $shop, Carbon $now): bool
    {
        $local = $now->copy()->setTimezone((string) ($shop->timezone ?: 'Asia/Jerusalem'));
        $day = (string) Settings::get('search_subscriptions.send_day', $shop->id);
        $hour = (int) Settings::get('search_subscriptions.send_hour', $shop->id);

        return strtolower($local->englishDayOfWeek) === $day && $local->hour === $hour;
    }

    private function forShop(Shop $shop, Carbon $now): int
    {
        SearchSubscription::query()->where('status', SearchSubscription::ACTIVE)->whereNotNull('expires_at')->where('expires_at', '<', $now)
            ->get()->each(fn (SearchSubscription $s) => $s->stop('expired'));

        $sent = 0;
        $byAddress = SearchSubscription::query()->where('status', SearchSubscription::ACTIVE)->orderBy('confirmed_at')->get()->groupBy('email_hash');
        foreach ($byAddress as $hash => $subscriptions) {
            if ($this->sentThisWeek($hash, $now)) {
                continue;
            }
            if ($this->unopened($hash)) {
                $subscriptions->each(fn (SearchSubscription $s) => $s->stop('unopened'));

                continue;
            }
            $sent += $this->sendTo($shop, (string) $hash, $subscriptions->all(), $now) ? 1 : 0;
        }

        return $sent;
    }

    private function sentThisWeek(string $hash, Carbon $now): bool
    {
        return SearchSubscriptionSend::query()->where('email_hash', $hash)->where('kind', 'weekly')->where('sent_at', '>', $now->copy()->subDays(6))->exists();
    }

    /** The address's last weekly emails, as many as stop it, were neither opened nor clicked. */
    private function unopened(string $hash): bool
    {
        $limit = (int) Settings::get('search_subscriptions.unopened_stop');
        $last = SearchSubscriptionSend::query()->where('email_hash', $hash)->where('kind', 'weekly')->latest('sent_at')->limit($limit)->get();

        return $last->count() >= $limit && $last->every(fn (SearchSubscriptionSend $s): bool => $s->opened_at === null && $s->clicked_at === null);
    }

    /** @param  list<SearchSubscription>  $subscriptions */
    private function sendTo(Shop $shop, string $hash, array $subscriptions, Carbon $now): bool
    {
        $cap = (int) Settings::get('search_subscriptions.products_per_email');
        $already = SearchSubscriptionProduct::query()->where('email_hash', $hash)->pluck('product_id')->flip()->all();
        $taken = [];
        $lists = [];
        foreach ($subscriptions as $subscription) {
            $lists[$subscription->id] = $this->newFor($subscription, $already);
        }

        // the best of each search in turn, so one search never fills the whole email; a product under its first search only
        $picked = array_fill_keys(array_keys($lists), []);
        $total = 0;
        while ($total < $cap && $lists !== []) {
            foreach (array_keys($lists) as $id) {
                while ($lists[$id] !== [] && isset($taken[$lists[$id][0]->id])) {
                    array_shift($lists[$id]);
                }
                if ($lists[$id] === []) {
                    unset($lists[$id]);

                    continue;
                }
                $product = array_shift($lists[$id]);
                $taken[$product->id] = true;
                $picked[$id][] = $product;
                if (++$total >= $cap) {
                    break;
                }
            }
        }
        if ($taken === []) {
            return false;
        }

        $send = SearchSubscriptionSend::query()->create(['shop_id' => $shop->id, 'email_hash' => $hash, 'kind' => 'weekly', 'queries' => [], 'products' => count($taken), 'sent_at' => $now]);
        $searches = [];
        foreach ($subscriptions as $subscription) {
            if (($picked[$subscription->id] ?? []) === []) {
                continue;
            }
            $searches[] = [
                'query' => Subscribers::cut($subscription->query, 80),
                'stop' => Subscribers::stopUrl($subscription),
                'products' => array_map(fn (CatalogProduct $p): array => [
                    'title' => Subscribers::cut((string) $p->title, 60),
                    'price' => self::price($p),
                    'image' => (string) ($p->image_url ?? ''),
                    'url' => Subscribers::clickUrl($send->id, Subscribers::tagged((string) $p->url)),
                ], $picked[$subscription->id]),
            ];
        }
        $send->forceFill(['queries' => array_column($searches, 'query')])->save();

        $email = $subscriptions[0]->email;
        try {
            Mail::to($email)->send(new WeeklyNewArrivalsMail($shop->id, (string) $shop->name, $send->id, $hash, $searches, $shop->domain ? 'https://'.$shop->domain : ''));
        } catch (Throwable $e) {
            $send->delete();
            Log::warning('search subscriptions: a weekly email failed', ['shop' => $shop->id, 'error' => $e->getMessage()]);

            return false;
        }

        foreach ($subscriptions as $subscription) {
            foreach ($picked[$subscription->id] ?? [] as $product) {
                SearchSubscriptionProduct::query()->create(['shop_id' => $shop->id, 'email_hash' => $hash, 'product_id' => $product->id, 'subscription_id' => $subscription->id, 'sent_at' => $now]);
            }
        }

        return true;
    }

    /**
     * The search's new products for the address, best first: found by spelling, added after it was confirmed, not in
     * a bulk import, in stock, never sent to the address.
     *
     * @param  array<string, int>  $already  product ids sent to the address
     * @return list<CatalogProduct>
     */
    private function newFor(SearchSubscription $subscription, array $already): array
    {
        if ($subscription->confirmed_at === null) {
            return [];
        }
        $ids = $this->products->find($subscription->shop_id, $subscription->query, 60);
        if ($ids === []) {
            return [];
        }
        $rank = array_flip(array_map('strval', $ids));
        $found = CatalogProduct::query()->whereIn('external_id', array_map('strval', $ids))->whereNull('removed_at')
            ->where('in_stock', true)->where('created_at', '>', $subscription->confirmed_at)->get()
            ->reject(fn (CatalogProduct $p): bool => isset($already[$p->id]) || $this->bulk($p))
            ->sortBy(fn (CatalogProduct $p): int => $rank[(string) $p->external_id] ?? PHP_INT_MAX);

        return $found->values()->all();
    }

    /** @var array<string, bool> */
    private array $bulkHours = [];

    /** A product added in an hour that added as many as a bulk import does: none of them is "new" to a shopper. */
    private function bulk(CatalogProduct $product): bool
    {
        $limit = (int) Settings::get('search_subscriptions.bulk_import_skip');
        if ($limit <= 0) {
            return false;
        }
        $hour = $product->created_at->copy()->startOfHour();
        $key = $hour->toIso8601String();

        return $this->bulkHours[$key] ??= CatalogProduct::query()->whereBetween('created_at', [$hour, $hour->copy()->addHour()])->count() >= $limit;
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
