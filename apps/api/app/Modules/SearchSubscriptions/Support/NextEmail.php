<?php

namespace App\Modules\SearchSubscriptions\Support;

use App\Core\Facades\Settings;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Search\Contracts\ProductsForQuery;
use App\Modules\SearchSubscriptions\Models\SearchSubscription;
use App\Modules\SearchSubscriptions\Models\SearchSubscriptionProduct;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * What an address's next weekly email would hold, by one rule for the send (SendWeekly) and for the owner's page:
 *
 * - products added to the store after the search was confirmed (a product back in stock is not new), none from a bulk
 *   import (search_subscriptions.bulk_import_skip products in one hour), not removed, in stock now;
 * - found by the store's own search by spelling (ProductsForQuery): strict, a product missed rather than one unrelated;
 * - never one sent to the address before, at most search_subscriptions.products_per_email, the best of each search in
 *   turn, a product under its first search only.
 *
 * Built for thousands of addresses: the store's new products are read once (ids and dates only), each search runs once
 * for everyone who follows it (by its query_key, in the spelling most of them typed), and only when the store has a new
 * product younger than someone's confirmation at all. One instance for one run in one store, inside its tenant.
 */
final class NextEmail
{
    private string $shopId = '';

    /** @var array<string, string> new product id => created_at ('Y-m-d H:i:s'), in stock, not removed, no bulk import */
    private array $candidates = [];

    /** @var array<string, list<string>> external id => product ids among the candidates */
    private array $byExternal = [];

    /** The youngest candidate's created_at: a search confirmed after it has nothing new, and is not searched. */
    private string $newest = '';

    /** @var array<string, string> query_key => the spelling searched */
    private array $spellings = [];

    /** @var array<string, list<string>> query_key => candidate ids the search finds, best first */
    private array $ranked = [];

    private int $searched = 0;

    public function __construct(private readonly ProductsForQuery $products) {}

    /**
     * Ready for a run: the store's new products since $since (the earliest confirmation among the subscriptions it will be
     * asked about; null: none is confirmed, nothing is new), and the spelling of each search ($keys: only these).
     *
     * @param  list<string>|null  $keys
     */
    public function start(string $shopId, ?CarbonInterface $since, ?array $keys = null): self
    {
        $this->shopId = $shopId;
        $this->candidates = $this->byExternal = $this->spellings = $this->ranked = [];
        $this->newest = '';
        $this->searched = 0;
        if ($since === null || $keys === []) {
            return $this;
        }

        $this->readCandidates($since);
        if ($this->candidates !== []) {
            $this->readSpellings($keys);
        }

        return $this;
    }

    /** How many searches ran (each distinct search once, and only when something new could match it). */
    public function searched(): int
    {
        return $this->searched;
    }

    /**
     * The next email of each address: by address, by subscription, the product ids it would carry, in order.
     *
     * @param  array<string, iterable<SearchSubscription>>  $byAddress  each address's active subscriptions, first confirmed first
     * @return array<string, array<string, list<string>>> email_hash => subscription id => product ids (only those with some)
     */
    public function pick(array $byAddress): array
    {
        if ($this->candidates === [] || $byAddress === []) {
            return [];
        }
        $cap = (int) Settings::get('search_subscriptions.products_per_email');
        $already = $this->alreadySent(array_map('strval', array_keys($byAddress)));

        $picks = [];
        foreach ($byAddress as $hash => $subscriptions) {
            $lists = [];
            foreach ($subscriptions as $subscription) {
                $lists[(string) $subscription->id] = $this->newFor($subscription, $already[(string) $hash] ?? []);
            }

            // the best of each search in turn, so one search never fills the whole email; a product under its first search only
            $taken = [];
            $picked = [];
            $total = 0;
            while ($total < $cap && $lists !== []) {
                foreach (array_keys($lists) as $id) {
                    while ($lists[$id] !== [] && isset($taken[$lists[$id][0]])) {
                        array_shift($lists[$id]);
                    }
                    if ($lists[$id] === []) {
                        unset($lists[$id]);

                        continue;
                    }
                    $product = array_shift($lists[$id]);
                    $taken[$product] = true;
                    $picked[$id][] = $product;
                    if (++$total >= $cap) {
                        break;
                    }
                }
            }
            if ($picked !== []) {
                $picks[(string) $hash] = $picked;
            }
        }

        return $picks;
    }

    /**
     * The search's new products for the address, best first.
     *
     * @param  array<string, true>  $already  product ids sent to the address
     * @return list<string>
     */
    private function newFor(SearchSubscription $subscription, array $already): array
    {
        if ($subscription->confirmed_at === null) {
            return [];
        }
        $confirmed = $subscription->confirmed_at->format('Y-m-d H:i:s');
        if ($this->newest <= $confirmed) {
            return [];
        }

        $new = [];
        foreach ($this->ranked((string) $subscription->query_key, (string) $subscription->query) as $id) {
            if ($this->candidates[$id] > $confirmed && ! isset($already[$id])) {
                $new[] = $id;
            }
        }

        return $new;
    }

    /** @return list<string> the candidates the search finds, best first: searched once a run */
    private function ranked(string $key, string $typed): array
    {
        if (isset($this->ranked[$key])) {
            return $this->ranked[$key];
        }
        $this->searched++;
        $ranked = [];
        foreach ($this->products->find($this->shopId, $this->spellings[$key] ?? $typed, 60) as $external) {
            foreach ($this->byExternal[(string) $external] ?? [] as $id) {
                $ranked[$id] = true;
            }
        }

        return $this->ranked[$key] = array_map('strval', array_keys($ranked));
    }

    /**
     * The store's products added since $since that a shopper may be told of: in stock, not removed, not in an hour that
     * added as many as a bulk import does. Ids and dates only, read in slices.
     */
    private function readCandidates(CarbonInterface $since): void
    {
        $bulk = $this->bulkHours($since);
        $query = CatalogProduct::query()->toBase()->select(['id', 'external_id', 'created_at'])
            ->whereNull('removed_at')->where('in_stock', true)->where('created_at', '>', $since);
        // a few bulk hours are left out by the database; many (a store just installed) are left out here as they come
        if ($bulk !== [] && count($bulk) <= 50) {
            foreach (array_keys($bulk) as $hour) {
                $query->whereNot(fn ($q) => $q->where('created_at', '>=', $hour.':00:00')->where('created_at', '<=', $hour.':59:59'));
            }
        }

        foreach ($query->lazyById(2000, 'id') as $row) {
            $created = substr((string) $row->created_at, 0, 19);
            if (isset($bulk[substr($created, 0, 13)])) {
                continue;
            }
            $this->candidates[(string) $row->id] = $created;
            $this->byExternal[(string) $row->external_id][] = (string) $row->id;
            if ($created > $this->newest) {
                $this->newest = $created;
            }
        }
    }

    /**
     * The hours since $since in which the store added as many products as a bulk import does: none of them is "new" to a
     * shopper. One grouped count, by the hour each was added ('Y-m-d H').
     *
     * @return array<string, true>
     */
    private function bulkHours(CarbonInterface $since): array
    {
        $limit = (int) Settings::get('search_subscriptions.bulk_import_skip');
        if ($limit <= 0) {
            return [];
        }
        $hour = match (DB::connection()->getDriverName()) {
            'pgsql' => "to_char(created_at, 'YYYY-MM-DD HH24')",
            'mysql', 'mariadb' => "date_format(created_at, '%Y-%m-%d %H')",
            default => 'substr(created_at, 1, 13)',
        };

        return CatalogProduct::query()->toBase()->selectRaw($hour.' as hour')
            ->where('created_at', '>=', $since->copy()->startOfHour())
            ->groupByRaw($hour)->havingRaw('count(*) >= ?', [$limit])
            ->pluck('hour')->mapWithKeys(fn ($h): array => [(string) $h => true])->all();
    }

    /**
     * Each search's spelling: the one most of its followers typed (ties by the words, so a run always reads the same).
     *
     * @param  list<string>|null  $keys
     */
    private function readSpellings(?array $keys): void
    {
        $rows = SearchSubscription::query()->toBase()->where('status', SearchSubscription::ACTIVE)
            ->when($keys !== null, fn ($q) => $q->whereIn('query_key', $keys))
            ->selectRaw('query_key, query, count(*) as people')->groupBy('query_key', 'query')->get();

        $best = [];
        foreach ($rows as $row) {
            $key = (string) $row->query_key;
            $people = (int) $row->people;
            $query = (string) $row->query;
            if (! isset($best[$key]) || $people > $best[$key][1] || ($people === $best[$key][1] && strcmp($query, $best[$key][0]) < 0)) {
                $best[$key] = [$query, $people];
            }
        }
        $this->spellings = array_map(fn (array $b): string => $b[0], $best);
    }

    /**
     * The products already sent to these addresses, among those that could be new: a product added after $since can only
     * have been sent after it, so older rows are not read.
     *
     * @param  list<string>  $hashes
     * @return array<string, array<string, true>>
     */
    private function alreadySent(array $hashes): array
    {
        $oldest = min($this->candidates);
        $sent = [];
        foreach (array_chunk($hashes, 500) as $slice) {
            $rows = SearchSubscriptionProduct::query()->toBase()->whereIn('email_hash', $slice)->where('sent_at', '>=', $oldest)
                ->get(['email_hash', 'product_id']);
            foreach ($rows as $row) {
                $sent[(string) $row->email_hash][(string) $row->product_id] = true;
            }
        }

        return $sent;
    }
}
