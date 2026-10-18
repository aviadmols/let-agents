<?php

namespace App\Modules\Catalog\Support;

use App\Modules\Admin\Contracts\TimelineEntry;
use App\Modules\Admin\Contracts\TimelineSource;
use App\Modules\Catalog\Filament\Operator\Resources\CatalogContents\CatalogContentResource;
use App\Modules\Catalog\Filament\Operator\Resources\CatalogProducts\CatalogProductResource;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Tenancy\Models\Shop;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * What this module brings to the timeline: the products that came into the catalog and the ones that
 * left it, and the pages and posts that came in, one line a day each, naming the first few.
 */
final class CatalogTimeline implements TimelineSource
{
    private const NAMED = 6;

    public function entries(Shop $shop, CarbonInterface $since): array
    {
        $entries = [];
        $products = CatalogProductResource::getUrl();
        $content = CatalogContentResource::getUrl();

        $this->daily($entries, CatalogProduct::query()->where('created_at', '>=', $since)->orderByDesc('created_at')->get(['id', 'title', 'created_at']),
            'created_at', 'catalog.products_in', 'catalog::timeline.products_in', TimelineEntry::CATALOG, $products);
        $this->daily($entries, CatalogProduct::query()->whereNotNull('removed_at')->where('removed_at', '>=', $since)->orderByDesc('removed_at')->get(['id', 'title', 'removed_at']),
            'removed_at', 'catalog.products_out', 'catalog::timeline.products_out', TimelineEntry::CATALOG, $products);
        $this->daily($entries, CatalogContent::query()->where('type', '!=', CatalogContent::KNOWLEDGE)->where('created_at', '>=', $since)->orderByDesc('created_at')->get(['id', 'title', 'type', 'created_at']),
            'created_at', 'catalog.content_in', 'catalog::timeline.content_in', TimelineEntry::KNOWLEDGE, $content);

        return $entries;
    }

    /**
     * One entry a day for the rows, named after the first few.
     *
     * @param  list<TimelineEntry>  $entries
     * @param  Collection<int, Model>  $rows
     */
    private function daily(array &$entries, Collection $rows, string $column, string $key, string $lang, string $kind, string $url): void
    {
        foreach ($rows->groupBy(fn (Model $row): string => $row->{$column}->timezone('Asia/Jerusalem')->format('Y-m-d')) as $day => $group) {
            $titles = $group->map(fn (Model $row): string => (string) $row->title)->all();
            $lines = array_slice($titles, 0, self::NAMED);

            if (count($titles) > self::NAMED) {
                $lines[] = __('catalog::timeline.more', ['n' => count($titles) - self::NAMED]);
            }

            $entries[] = new TimelineEntry(
                key: $key.'.'.$day,
                kind: $kind,
                at: $group->first()->{$column},
                title: __($lang, ['n' => count($titles)]),
                lines: $lines,
                url: $url,
            );
        }
    }
}
