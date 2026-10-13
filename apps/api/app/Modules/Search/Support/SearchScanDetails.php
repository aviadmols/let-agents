<?php

namespace App\Modules\Search\Support;

use App\Modules\Admin\Contracts\ScanDetails;
use App\Modules\Search\Models\SearchIndex;
use App\Modules\Search\Models\SearchPageTags;
use App\Modules\Tenancy\Models\Shop;

/** The search steps of the scan: what the search holds, and the tags written for pages. */
final class SearchScanDetails implements ScanDetails
{
    private const TAGS = 15;

    public function scanDetails(Shop $shop): array
    {
        $details = ['search' => [['title' => __('search::scan.index_title'), 'rows' => self::indexRows()]]];

        $pages = SearchPageTags::query()->latest('updated_at')->limit(300)->get(['tags']);
        $tags = [];
        foreach ($pages as $page) {
            foreach ((array) $page->tags as $tag) {
                $label = trim((string) ($tag['label'] ?? ''));
                if ($label !== '') {
                    $tags[$label] = ($tags[$label] ?? 0) + 1;
                }
            }
        }
        arsort($tags);

        $details['tags'] = [[
            'title' => __('search::scan.tags_title', ['pages' => number_format(SearchPageTags::query()->count()), 'tags' => number_format(count($tags))]),
            'rows' => array_map(fn (string $label, int $n): array => [$label, __('search::scan.tag_pages', ['count' => $n])], array_keys(array_slice($tags, 0, self::TAGS, true)), array_slice($tags, 0, self::TAGS, true)),
        ]];

        return $details;
    }

    /** @return list<array{0: string, 1: string}> */
    public static function indexRows(): array
    {
        $index = SearchIndex::query()->latest('built_at')->first();

        if ($index === null) {
            return [[__('search::scan.not_built'), '']];
        }

        $counts = (array) $index->counts;

        return [
            [__('search::scan.products'), number_format((int) ($counts['product'] ?? 0))],
            [__('search::scan.content'), number_format((int) ($counts['content'] ?? 0))],
            [__('search::scan.categories'), number_format((int) ($counts['category'] ?? 0))],
            [__('search::scan.built'), $index->built_at->diffForHumans()],
        ];
    }

    /** How many products the search holds now, for the home's status line. */
    public static function products(): int
    {
        return (int) (((array) SearchIndex::query()->latest('built_at')->first()?->counts)['product'] ?? 0);
    }
}
