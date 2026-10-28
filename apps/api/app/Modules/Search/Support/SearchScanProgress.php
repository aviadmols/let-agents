<?php

namespace App\Modules\Search\Support;

use App\Core\Facades\Settings;
use App\Modules\Admin\Contracts\ScanProgress;
use App\Modules\Search\Models\SearchIndex;
use App\Modules\Search\Models\SearchPageTags;
use App\Modules\Tenancy\Models\Shop;

/**
 * How far the tags for pages are in the scan: the pages tagged in this scan, of the products and pages the search
 * holds, up to a run's share. Inside the shop.
 */
final class SearchScanProgress implements ScanProgress
{
    public function scanProgress(Shop $shop, array $began): array
    {
        $counts = (array) (SearchIndex::query()->value('counts') ?? []);
        $pages = (int) ($counts['product'] ?? 0) + (int) ($counts['content'] ?? 0);
        $share = (int) Settings::get('search.tags_per_run', $shop->id);
        $total = $share > 0 ? min($share, $pages) : $pages;
        $tagged = isset($began['tags']) ? SearchPageTags::query()->where('updated_at', '>=', $began['tags'])->count() : 0;

        return ['tags' => ['done' => $total > 0 ? min($tagged, $total) : $tagged, 'total' => $total > 0 ? $total : null]];
    }
}
