<?php

namespace App\Modules\Search\Filament\Merchant\Pages;

use App\Core\Facades\Features;
use App\Core\Filament\EditsShopSettings;
use App\Core\Settings\SettingRegistry;
use App\Core\Tenancy\TenantContext;
use App\Modules\Retrieval\Contracts\SemanticSearch;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Settings · The search window, as the designer's panel lays it out: whether Lets search runs on the
 * site at all (the card's switch), whether it takes over the theme's own search box, and what the drawer
 * offers: photos, questions, similar items, prices, the cart, the wishlist and compare, "Helpful?" under
 * an answer, and the product page's row when a product or a size is sold out. Each is a feature or a
 * setting the drawer reads (SearchIndexController).
 */
class SearchWindowSettings extends Page
{
    use EditsShopSettings;

    public static string $sideGroup = 'shoppers';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlass;

    protected static string|UnitEnum|null $navigationGroup = 'settings';

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'settings/search';

    protected string $view = 'search::merchant.settings.search';

    public static function getNavigationLabel(): string
    {
        return __('search::shop_settings.search.nav');
    }

    public function getTitle(): string
    {
        return __('admin::shop_settings.title');
    }

    public function getSubheading(): ?string
    {
        return __('admin::shop_settings.subheading');
    }

    protected function fields(): array
    {
        return [
            'storefront' => 'feature:search.storefront',
            'take_over' => 'feature:search.take_over_field',
            'photos' => 'feature:search.photos',
            'ask' => 'feature:assistant.on_search',
            'similar' => 'feature:search.similar',
            'similar_by' => 'setting:search.similar_by',
            'price' => 'setting:search.show_price',
            'cart' => 'setting:search.show_cart',
            'per_group' => 'setting:search.results_per_group',
            'wishlist' => 'feature:shortlist.wishlist',
            'compare' => 'feature:shortlist.compare',
            'rate' => 'feature:search.rate_answers',
            'out_of_stock' => 'feature:search.out_of_stock_similar',
        ];
    }

    /** Photo search needs the shop's pictures scanned: turning it on starts the scan too. */
    protected function saveMore(): void
    {
        if (($this->data['photos'] ?? false) && ! Features::enabled('retrieval.image_index', $this->shopId())) {
            Features::override('retrieval.image_index', true, $this->shopId());
        }
    }

    /** Whether the shop's pictures already have vectors: the camera shows only then. */
    public function picturesReady(): bool
    {
        return app(TenantContext::class)->run($this->shopId(), fn (): bool => app(SemanticSearch::class)->picturesReady());
    }

    /** @return array{min: int, max: int} the bounds of results per group, as declared */
    public function perGroupBounds(): array
    {
        $definition = app(SettingRegistry::class)->get('search.results_per_group');

        return ['min' => (int) ($definition->min ?? 1), 'max' => (int) ($definition->max ?? 48)];
    }
}
