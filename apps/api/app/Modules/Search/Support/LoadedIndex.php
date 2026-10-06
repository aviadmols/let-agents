<?php

namespace App\Modules\Search\Support;

use App\Modules\Search\Models\SearchIndex;

/**
 * A shop's search index, read once per version and kept in the worker.
 *
 * Building the lookup structure takes about a fifth of a second for a thousand products, and
 * Octane keeps the worker alive between requests, so it is built once per index version rather
 * than per search. A handful of shops are kept; the oldest is dropped first.
 */
final class LoadedIndex
{
    private const KEEP = 16;

    /** @var array<string, array{hash: string, records: array<string, array<string, mixed>>, engine: array<string, mixed>}> */
    private static array $loaded = [];

    /**
     * The shop's records by id, as stored, and the lookup structure HebrewSearch reads.
     *
     * @return array{hash: string, records: array<string, array<string, mixed>>, engine: array<string, mixed>}|null
     */
    public static function for(string $shopId): ?array
    {
        $row = SearchIndex::query()->where('shop_id', $shopId)->first(['id', 'hash']);

        if ($row === null) {
            return null;
        }

        if ((self::$loaded[$shopId]['hash'] ?? null) === $row->hash) {
            return self::$loaded[$shopId];
        }

        $data = json_decode((string) SearchIndex::query()->whereKey($row->id)->value('items'), true);
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        $records = [];
        $source = [];

        foreach ($items as $item) {
            $records[(string) $item['id']] = $item;
            $source[] = ['id' => (string) $item['id'], 'title' => (string) $item['title'], 'keywords' => (string) ($item['kw'] ?? '')];
        }

        unset(self::$loaded[$shopId]);

        if (count(self::$loaded) >= self::KEEP) {
            array_shift(self::$loaded);
        }

        return self::$loaded[$shopId] = [
            'hash' => $row->hash,
            'records' => $records,
            'engine' => HebrewSearch::build($source),
        ];
    }

    public static function forget(): void
    {
        self::$loaded = [];
    }
}
