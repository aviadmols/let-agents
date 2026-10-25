<?php

namespace App\Modules\Search\Actions;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Retrieval\Contracts\TermVectors;
use App\Modules\Search\Models\SearchIndex;
use App\Modules\Search\Support\HebrewSearch;

/**
 * The store's words as vectors, each once, after its index is built (the `search index` step, by a scan and at night):
 * a word a shopper types that it does not write ("מפוספס") is then read as one it does ("פסים", SearchCatalog). The
 * index build stays code only; this is its own step, paid and counted (TermVectors). Only new words cost anything.
 */
final class RememberStoreWords
{
    /** A word the products say counts as the store's when at least this many say it, and at most this share of them. */
    private const TERM_MIN_PRODUCTS = 2;

    private const TERM_MAX_SHARE = 0.5;

    public function __construct(private readonly TenantContext $tenant) {}

    /** How many of the store's words were new and embedded now. */
    public function handle(string $shopId): int
    {
        if (! Features::enabled('search.word_vectors', $shopId) || ! app()->bound(TermVectors::class)) {
            return 0;
        }

        return $this->tenant->run($shopId, function () use ($shopId): int {
            $data = json_decode((string) SearchIndex::query()->value('items'), true);
            if (! is_array($data)) {
                return 0;
            }

            return app(TermVectors::class)->remember($shopId, self::vocabulary((array) ($data['items'] ?? []), (array) ($data['facets'] ?? []), (int) Settings::get('search.vocabulary_terms', $shopId)));
        });
    }

    /**
     * The words the store says: its filters' values, then the words of its products' titles and words (tags, picture
     * words) that some say and not most, the most said first, as Hebrew writes them.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  list<array<string, mixed>>  $facets
     * @return list<string>
     */
    public static function vocabulary(array $items, array $facets, int $max): array
    {
        if ($max < 1) {
            return [];
        }
        $terms = [];
        foreach ($facets as $facet) {
            foreach ((array) ($facet['v'] ?? []) as $value) {
                $label = trim((string) ($value['l'] ?? ''));
                if (mb_strlen($label) >= 2 && preg_match('/\p{L}/u', $label) === 1) {
                    $terms[$label] = PHP_INT_MAX;
                }
            }
        }
        $said = [];
        $products = 0;
        foreach ($items as $item) {
            if (($item['t'] ?? null) !== 'product') {
                continue;
            }
            $products++;
            foreach (array_unique(explode(' ', HebrewSearch::normalize((string) ($item['title'] ?? '').' '.(string) ($item['kw'] ?? '')))) as $word) {
                if (mb_strlen($word) >= 3 && preg_match('/^\p{L}+$/u', $word) === 1 && ! HebrewSearch::isFiller($word)) {
                    $said[$word] = ($said[$word] ?? 0) + 1;
                }
            }
        }
        foreach ($said as $word => $n) {
            if ($n >= self::TERM_MIN_PRODUCTS && $n <= max(self::TERM_MIN_PRODUCTS, $products * self::TERM_MAX_SHARE)) {
                $terms[HebrewSearch::withFinals((string) $word)] ??= $n;
            }
        }
        arsort($terms);

        return array_slice(array_map('strval', array_keys($terms)), 0, $max);
    }
}
