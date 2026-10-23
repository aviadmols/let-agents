<?php

namespace App\Modules\Admin\Filament\Merchant\Pages;

use App\Core\Filament\EditsShopSettings;
use App\Core\Localization\Money;
use App\Modules\Admin\Actions\SaveShopProfile;
use App\Modules\Tenancy\Models\Shop;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Settings · General, as the designer's panel lays it out: the store's name, whether it is a store, a
 * content site or both, its languages (the first is the main one, the language its content is read in),
 * what it sells in its own words, and its time zone and currency as the connection set them.
 */
class GeneralSettings extends Page
{
    use EditsShopSettings;

    public const KINDS = SaveShopProfile::KINDS;

    /** The languages a store can add, in their own names. */
    public const LANGUAGES = SaveShopProfile::LANGUAGES;

    public static string $sideGroup = 'store';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'settings';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'settings/general';

    protected string $view = 'admin::merchant.settings.general';

    public static function getNavigationLabel(): string
    {
        return __('admin::shop_settings.general.title');
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
        return [];
    }

    protected function loadMore(): void
    {
        $shop = $this->shop();

        $this->data += app(SaveShopProfile::class)->load($shop) + [
            'zone' => $shop->timezone.' · '.self::symbol((string) $shop->currency),
        ];
    }

    protected function checkMore(): void
    {
        $this->problems += app(SaveShopProfile::class)->problems($this->data);
    }

    protected function saveMore(): void
    {
        app(SaveShopProfile::class)->handle($this->shop(), $this->data);
    }

    public static function symbol(string $currency): string
    {
        return Money::symbol($currency);
    }

    private function shop(): Shop
    {
        /** @var Shop $shop */
        $shop = Filament::getTenant();

        return $shop;
    }
}
