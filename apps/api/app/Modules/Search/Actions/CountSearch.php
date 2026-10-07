<?php

namespace App\Modules\Search\Actions;

use App\Modules\Search\Models\SearchClick;
use App\Modules\Search\Models\SearchTerm;
use App\Modules\Search\Support\HebrewSearch;
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

    public function search(string $shopId, string $raw, int $results): void
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
    }

    public function click(string $shopId, string $raw, string $item, string $title): void
    {
        $query = $this->query($raw);

        if ($query === '' || ! preg_match('/^[pck]:[A-Za-z0-9_.:-]{1,64}$/', $item)) {
            return;
        }

        $day = now()->toDateString();
        $this->ensure($shopId, $query);
        SearchTerm::query()->whereDate('day', $day)->where('query', $query)->incrementEach(['clicks' => 1], ['updated_at' => now()]);

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
