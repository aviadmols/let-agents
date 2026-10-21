<?php

namespace App\Modules\Enrichment\Filament\Merchant\Widgets;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Contracts\OverviewBlock;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Filament\Merchant\Pages\ScanSummary;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

/**
 * What came in or changed lately on the shop's home: its products and its pages and posts, the newest first, each
 * opening on the scan screen with what was read from it. A product the store changed is here within minutes (the
 * catalogue looks every ten); a page or a post, after the night's reading.
 */
final class RecentlyRead extends Widget implements OverviewBlock
{
    protected string $view = 'enrichment::merchant.recently-read';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 30;

    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        return Filament::getTenant() instanceof Shop;
    }

    /** @return array{rows: list<array{title: string, kind: string, new: bool, at: string, url: string, thumb: string|null}>} */
    protected function getViewData(): array
    {
        $shop = Filament::getTenant();
        if (! $shop instanceof Shop) {
            return ['rows' => []];
        }

        $shown = (int) Settings::get('enrichment.recently_read', $shop->id);
        $row = fn (CatalogProduct|CatalogContent $item, string $tab, string $kind): array => [
            'title' => (string) $item->title,
            'kind' => $kind,
            // came in, rather than changed: its row was written once
            'new' => $item->created_at !== null && $item->updated_at !== null && $item->updated_at->diffInMinutes($item->created_at, true) < 1,
            'at' => (string) $item->updated_at?->locale(app()->getLocale())->diffForHumans(),
            'stamp' => (int) $item->updated_at?->timestamp,
            'url' => ScanSummary::getUrl(['tab' => $tab, 'open' => (string) $item->id], tenant: $shop),
            'thumb' => $item->image_url ?? null,
        ];

        $rows = app(TenantContext::class)->run((string) $shop->id, fn () => CatalogProduct::query()->active()->latest('updated_at')->limit($shown)->get()->map(fn (CatalogProduct $p): array => $row($p, 'products', 'product'))
            ->concat(CatalogContent::query()->active()->whereIn('type', [CatalogContent::PAGE, CatalogContent::POST])->latest('updated_at')->limit($shown)->get()
                ->map(fn (CatalogContent $c): array => $row($c, 'pages', (string) $c->type)))
            ->sortByDesc('stamp')->take($shown)->values()->all());

        return ['rows' => $rows];
    }
}
