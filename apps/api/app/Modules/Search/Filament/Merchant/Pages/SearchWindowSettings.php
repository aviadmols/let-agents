<?php

namespace App\Modules\Search\Filament\Merchant\Pages;

use App\Core\Facades\Features;
use App\Core\Filament\EditsShopSettings;
use App\Core\Settings\SettingRegistry;
use App\Core\Tenancy\TenantContext;
use App\Modules\Retrieval\Contracts\SemanticSearch;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Search\Actions\BuildSearchIndex;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Settings · The search window, as the designer's panel lays it out: whether Lets search runs on the
 * site at all (the card's switch), whether it takes over the theme's own search box, whether a new shopper is
 * invited to try it once, the round button at the bottom of the screen (shown or not, its side and height), and what the drawer offers: photos, questions, similar items, prices, the cart, the wishlist and compare, "Helpful?" under
 * an answer, the product page's row when a product or a size is sold out, and whether the posts Lets wrote
 * are search results. Each is a feature or a setting the drawer reads (SearchIndexController).
 */
class SearchWindowSettings extends Page
{
    use EditsShopSettings;

    /** The posts Lets wrote went in or out of the results on this save: the search index is built again (not a Livewire property). */
    private bool $postsChanged = false;

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
            'nudge' => 'feature:search.nudge',
            'nudge_at' => 'setting:search.nudge_at',
            'float' => 'setting:search.float_button',
            'float_side' => 'setting:search.float_side',
            'float_bottom' => 'setting:search.float_bottom',
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
            'lets_posts' => 'feature:search.lets_posts',
        ];
    }

    protected function checkMore(): void
    {
        $this->postsChanged = Features::enabled('search.lets_posts', $this->shopId()) !== (bool) ($this->data['lets_posts'] ?? false);
    }

    /** What is in the search index changed: built again now, on the queue, as after a catalogue sync. Code only. */
    protected function saved(): void
    {
        if ($this->postsChanged) {
            $shopId = $this->shopId();
            dispatch(fn () => app(BuildSearchIndex::class)->handle($shopId, RunTrigger::System));
        }
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

    /** @return array{min: int, max: int} a number's bounds, as its setting declares them (results per group, the button's height) */
    public function bounds(string $setting): array
    {
        $definition = app(SettingRegistry::class)->get($setting);

        return ['min' => (int) ($definition->min ?? 0), 'max' => (int) ($definition->max ?? 0)];
    }
}
