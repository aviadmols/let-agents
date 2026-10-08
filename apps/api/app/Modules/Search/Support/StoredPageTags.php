<?php

namespace App\Modules\Search\Support;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Modules\Search\Actions\SearchCatalog;
use App\Modules\Search\Contracts\PageTags;
use App\Modules\Search\Models\SearchPageTags;

/** The page's accepted tags, each searched now, without being counted as a shopper's search. */
final class StoredPageTags implements PageTags
{
    public function __construct(private readonly SearchCatalog $search) {}

    /**
     * Tags are written and shown for a shop that turned them on, or that chose the tag bank as
     * the look of its on-page module: choosing the look is enough, with no second switch to find.
     */
    public static function wanted(string $shopId): bool
    {
        if (Features::enabled('search.page_tags', $shopId)) {
            return true;
        }

        try {
            return Settings::get('widget.layout', $shopId) === 'tags';
        } catch (\Throwable) {
            return false;
        }
    }

    public function forPage(string $shopId, string $type, string $externalId, int $perTag): array
    {
        if (! self::wanted($shopId)) {
            return [];
        }

        $row = SearchPageTags::query()->where('page_type', $type)->where('external_id', $externalId)->first();
        $out = [];

        foreach ($row?->shown() ?? [] as $tag) {
            $found = $this->search->handle($shopId, $tag['query'], ['product', 'content'], count: false, perGroup: $perTag + 1);
            $products = array_values(array_diff(array_column($found['groups']['product'], 'external_id'), $type === 'product' ? [$externalId] : []));
            $content = array_values(array_diff(array_column($found['groups']['content'], 'external_id'), $type === 'content' ? [$externalId] : []));

            if ($products === [] && $content === []) {
                continue;
            }

            $out[] = ['label' => $tag['label'], 'query' => $tag['query'], 'products' => array_slice($products, 0, $perTag), 'content' => array_slice($content, 0, 3)];
        }

        return $out;
    }
}
