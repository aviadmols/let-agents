<?php

namespace App\Modules\Search\Actions;

use App\Core\Http\Visit;
use App\Modules\Analytics\Models\AnalyticsEvent;
use App\Modules\Search\Models\SearchClick;
use App\Modules\Search\Models\SearchLog;
use App\Modules\Search\Models\SearchSignal;
use App\Modules\Search\Models\SearchTerm;
use App\Modules\Search\Support\HebrewSearch;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Counts what shoppers searched and clicked, per shop, query and day. The query is normalized
 * first, so "ברגים" and "ברגים " are one row. Callers are already inside the shop's tenant.
 *
 * A search is counted once per browser tab: when it was sent to the server, or when the shopper
 * stopped typing for two seconds over the suggestions, or when a suggestion was clicked. The
 * storefront script keeps the tab's list so the three never count the same search twice.
 */
final class CountSearch
{
    public const MAX_QUERY = 120;

    /** How a search by photo is counted: one row a day, never the photo. */
    public const PHOTO = '[photo]';

    /** A search by an uploaded photo. */
    public function photo(string $shopId, int $results): void
    {
        $this->ensure($shopId, self::PHOTO);

        SearchTerm::query()->whereDate('day', now()->toDateString())->where('query', self::PHOTO)->incrementEach(
            ['searches' => 1, 'empty' => $results === 0 ? 1 : 0],
            ['last_results' => max(0, $results), 'updated_at' => now()],
        );
    }

    /** @param  array<string, string|null>  $visit  who searched (App\Core\Http\Visit); a row of its own when given */
    public function search(string $shopId, string $raw, int $results, array $visit = []): void
    {
        $query = $this->query($raw);

        if ($query === '') {
            return;
        }

        $this->ensure($shopId, $query);

        SearchTerm::query()->whereDate('day', now()->toDateString())->where('query', $query)->incrementEach(
            ['searches' => 1, 'empty' => $results === 0 ? 1 : 0],
            ['last_results' => max(0, $results), 'updated_at' => now()],
        );

        if ($visit !== []) {
            SearchLog::query()->create(['shop_id' => $shopId, 'query' => $query, 'results' => max(0, $results), 'created_at' => now()] + array_intersect_key($visit, array_flip(Visit::COLUMNS)));
        }
    }

    /** @param  array<string, string|null>  $visit */
    public function click(string $shopId, string $raw, string $item, string $title, array $visit = []): void
    {
        $query = $this->query($raw);

        if ($query === '' || ! preg_match('/^[pck]:[A-Za-z0-9_.:-]{1,64}$/', $item)) {
            return;
        }

        $day = now()->toDateString();
        $this->ensure($shopId, $query);
        SearchTerm::query()->whereDate('day', $day)->where('query', $query)->incrementEach(['clicks' => 1], ['updated_at' => now()]);

        // The search it came from, in the same session, within the hour: what it led to.
        if (($visit['session_id'] ?? null) !== null) {
            SearchLog::query()->where('session_id', $visit['session_id'])->where('query', $query)->whereNull('clicked')
                ->where('created_at', '>=', now()->subHour())->latest('id')->limit(1)->update(['clicked' => $item]);
        }

        SearchClick::query()->insertOrIgnore([
            'id' => (string) Str::ulid(),
            'shop_id' => $shopId,
            'day' => $day,
            'query' => $query,
            'item' => $item,
            'title' => mb_substr($title, 0, 300),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        SearchClick::query()->whereDate('day', $day)->where('query', $query)->where('item', $item)->incrementEach(['clicks' => 1], ['updated_at' => now()]);
    }

    /**
     * A product added to the cart from the search: its results, the assistant's picks, or the
     * product's own page after it was opened from them (within a week). Counted
     * on the query and the product, and, when the visitor is known, kept with the shop's events so
     * an order by the same visitor is attributed to the search (Analytics\Actions\RecordOrder).
     */
    public function added(string $shopId, string $raw, string $item, string $title, ?string $visitorId, string $path): void
    {
        $query = $this->query($raw);

        if ($query === '' || ! preg_match('/^p:([A-Za-z0-9_.:-]{1,64})$/', $item, $m)) {
            return;
        }

        $day = now()->toDateString();
        $this->ensure($shopId, $query);
        SearchTerm::query()->whereDate('day', $day)->where('query', $query)->incrementEach(['added' => 1], ['updated_at' => now()]);

        SearchClick::query()->insertOrIgnore([
            'id' => (string) Str::ulid(),
            'shop_id' => $shopId,
            'day' => $day,
            'query' => $query,
            'item' => $item,
            'title' => mb_substr($title, 0, 300),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        SearchClick::query()->whereDate('day', $day)->where('query', $query)->where('item', $item)->incrementEach(['added' => 1], ['updated_at' => now()]);

        if ($visitorId === null || ! preg_match('/^anon-[A-Za-z0-9_-]{16,64}$/', $visitorId)) {
            return;
        }

        AnalyticsEvent::query()->insertOrIgnore([
            'shop_id' => $shopId,
            'event_id' => (string) Str::ulid(),
            'type' => 'add_to_cart',
            // Also from the product's own page, after it was opened from the search: the search's add all the same.
            'page_type' => 'search',
            'page_path' => mb_substr($path !== '' && str_starts_with($path, '/') ? $path : '/', 0, 512),
            'item_external_id' => $m[1],
            'source' => AnalyticsEvent::SOURCE_SEARCH,
            'result' => 'added',
            'quantity' => 1,
            'visitor_hash' => AnalyticsEvent::visitorHash($shopId, $visitorId),
            // The search keeps no session of its own.
            'session_id' => 'search',
            'occurred_at' => now(),
            'created_at' => now(),
        ]);
    }

    /**
     * What a shopper did with a search, for the store to learn from (LearnFromSearches): its kind (SearchSignal::KINDS),
     * the search, and what it was done to (a product's id, a chip's words). Counted, and the shopper once a day: by a hash
     * of their anonymous id kept only until the day ends, never stored with it.
     */
    public function signal(string $shopId, string $kind, string $raw, string $value, ?string $visitorId): void
    {
        $query = $this->query($raw);
        $value = mb_substr(trim(strip_tags($value)), 0, 200);
        if ($query === '' || ! in_array($kind, SearchSignal::KINDS, true) || ($value === '' && $kind !== 'ask_after')) {
            return;
        }
        $day = now()->toDateString();
        SearchSignal::query()->insertOrIgnore([
            'id' => (string) Str::ulid(),
            'shop_id' => $shopId,
            'day' => $day,
            'kind' => $kind,
            'query' => $query,
            'value' => $value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $first = $visitorId !== null && preg_match('/^anon-[A-Za-z0-9_-]{16,64}$/', $visitorId) === 1
            && Cache::add('search:signal:'.hash('sha256', $shopId.'|'.$visitorId.'|'.$kind.'|'.$query.'|'.$value.'|'.$day), 1, now()->endOfDay());
        SearchSignal::query()->whereDate('day', $day)->where('kind', $kind)->where('query', $query)->where('value', $value)
            ->incrementEach(['count' => 1] + ($first ? ['visitors' => 1] : []), ['updated_at' => now()]);
    }

    private function query(string $raw): string
    {
        return mb_substr(HebrewSearch::normalize(mb_substr($raw, 0, 300)), 0, self::MAX_QUERY);
    }

    private function ensure(string $shopId, string $query): void
    {
        SearchTerm::query()->insertOrIgnore([
            'id' => (string) Str::ulid(),
            'shop_id' => $shopId,
            'day' => now()->toDateString(),
            'query' => $query,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
