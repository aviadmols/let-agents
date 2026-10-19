<?php

namespace App\Modules\Search\Filament\Merchant\Pages;

use App\Core\Facades\Settings;
use App\Core\Filament\EditsShopSettings;
use App\Modules\Tenancy\Models\Shop;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Settings · How it looks, as the designer's panel lays it out: the drawer, the product page row and
 * the wishlist in Lets' own look or the site's (font, main colour, corners, checked for contrast in the
 * browser), how product pictures sit in their square, the assistant's name and the widget's language.
 * The storefront reads them from /search/{site}/look and the index config.
 */
class LookSettings extends Page
{
    use EditsShopSettings;

    public const NAME_MAX = 60;

    public static string $sideGroup = 'store';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSwatch;

    protected static string|UnitEnum|null $navigationGroup = 'settings';

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'settings/look';

    protected string $view = 'search::merchant.settings.look';

    public static function getNavigationLabel(): string
    {
        return __('search::shop_settings.look.nav');
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
            'theme' => 'setting:search.theme',
            'images' => 'setting:search.image_fit',
            'language' => 'setting:search.widget_language',
        ];
    }

    /** The assistant's name as shoppers see it: the shop's own, else "<shop> AI". */
    protected function loadMore(): void
    {
        $name = trim((string) Settings::get('search.assistant_name', $this->shopId()));
        $this->data['assistant'] = $name !== '' ? $name : $this->defaultName();
    }

    protected function checkMore(): void
    {
        if (mb_strlen($this->name()) > self::NAME_MAX) {
            $this->problems['assistant'] = __('search::shop_settings.look.name_long');
        }
    }

    /** Empty, or the default name: nothing of the shop's own, so a renamed shop's assistant follows it. */
    protected function saveMore(): void
    {
        $name = $this->name();

        if ($name === '' || $name === $this->defaultName()) {
            Settings::clear('search.assistant_name', $this->shopId());

            return;
        }

        Settings::set('search.assistant_name', $name, $this->shopId());
    }

    protected function saved(): void
    {
        $this->loadMore();
    }

    private function name(): string
    {
        return (string) preg_replace('/^\s+|\s+$/u', '', (string) ($this->data['assistant'] ?? ''));
    }

    private function defaultName(): string
    {
        /** @var Shop $shop */
        $shop = Filament::getTenant();

        return trim((string) $shop->name).' AI';
    }
}
