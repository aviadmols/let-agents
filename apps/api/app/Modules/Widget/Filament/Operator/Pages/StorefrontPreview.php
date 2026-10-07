<?php

namespace App\Modules\Widget\Filament\Operator\Pages;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\NeedsShopContext;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Enrichment\Models\EnrichmentContentProduct;
use App\Modules\Enrichment\Models\EnrichmentRanking;
use App\Modules\Tenancy\Models\Shop;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Where the widget shows on a store, and links that show it to the store team before it is
 * live for everyone: the page address plus ?let_agents_preview=<key>. The key stays in a cookie for
 * 30 days, so the team can keep browsing the store.
 */
final class StorefrontPreview extends Page
{
    use NeedsShopContext;

    private const SAMPLE = 8;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEye;

    protected static string|UnitEnum|null $navigationGroup = 'discovery';

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'storefront-preview';

    protected string $view = 'widget::operator.preview';

    #[Url]
    public ?string $shop = null;

    public static function getNavigationLabel(): string
    {
        return __('widget::ui.preview.title');
    }

    public function getTitle(): string
    {
        return __('widget::ui.preview.title');
    }

    public function mount(): void
    {
        // The shop the panel is inside, so every screen agrees; the first by name when it is
        // looking across every shop.
        $this->shop ??= app(TenantContext::class)->id() ?? Shop::query()->orderBy('name')->value('id');
    }

    /** @return array<string, string> */
    public function shops(): array
    {
        return Shop::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    /** @return array<string, mixed>|null */
    public function details(): ?array
    {
        if ($this->shop === null) {
            return null;
        }

        return app(TenantContext::class)->run($this->shop, function (): array {
            $connection = StoreConnection::query()->latest()->first();
            $key = $connection?->previewKey();

            $products = EnrichmentRanking::query()
                ->with('product')
                ->where('rank', 1)
                ->whereHas('product', fn ($q) => $q->whereNull('removed_at')->where('in_stock', true)->whereNotNull('url'))
                ->orderByDesc('set_size')
                ->get()
                ->unique('product_id')
                ->take(self::SAMPLE)
                ->map(fn (EnrichmentRanking $r): array => ['title' => $r->product->title, 'url' => $this->previewUrl($r->product->url, $key)])
                ->values()
                ->all();

            $articles = EnrichmentContentProduct::query()
                ->with('content')
                ->whereHas('content', fn ($q) => $q->whereNull('removed_at')->whereNotNull('url'))
                ->orderBy('rank')
                ->get()
                ->unique('content_id')
                ->take(self::SAMPLE)
                ->map(fn (EnrichmentContentProduct $m): array => ['title' => $m->content->title, 'url' => $this->previewUrl($m->content->url, $key)])
                ->values()
                ->all();

            return [
                'connection' => $connection,
                'preview_key' => $key,
                'site_key' => $connection?->site_key,
                'plugin_version' => $connection?->info('plugin.version'),
                'placement' => [
                    'product' => [
                        'enabled' => Features::enabled('widget.on_products', $this->shop),
                        'selector' => Settings::get('widget.product_selector', $this->shop),
                        'position' => Settings::get('widget.product_position', $this->shop),
                    ],
                    'content' => [
                        'enabled' => Features::enabled('widget.on_content', $this->shop),
                        'selector' => Settings::get('widget.content_selector', $this->shop),
                        'position' => Settings::get('widget.content_position', $this->shop),
                    ],
                ],
                'floating' => Settings::get('widget.floating_fallback', $this->shop),
                'products' => $products,
                'articles' => $articles,
                'catalog_products' => CatalogProduct::query()->active()->count(),
                'configure_url' => url('operator/configuration').'?shop='.$this->shop,
            ];
        });
    }

    private function previewUrl(?string $url, ?string $key): ?string
    {
        if ($url === null || $key === null) {
            return $url;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').'let_agents_preview='.$key;
    }
}
