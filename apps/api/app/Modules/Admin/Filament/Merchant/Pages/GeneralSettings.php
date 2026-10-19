<?php

namespace App\Modules\Admin\Filament\Merchant\Pages;

use App\Core\Filament\EditsShopSettings;
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

    public const KINDS = ['store', 'content', 'both'];

    /** The languages a store can add, in their own names. */
    public const LANGUAGES = ['he' => 'עברית', 'en' => 'English', 'ar' => 'العربية', 'ru' => 'Русский', 'fr' => 'Français', 'es' => 'Español', 'de' => 'Deutsch'];

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
        $profile = (array) ($shop->profile ?? []);

        $this->data += [
            'name' => (string) $shop->name,
            'kind' => in_array($profile['kind'] ?? null, self::KINDS, true) ? $profile['kind'] : 'store',
            'languages' => array_values(array_unique([(string) $shop->content_locale, ...array_map('strval', (array) ($profile['languages'] ?? []))])),
            'sells' => array_values(array_map('strval', (array) ($profile['sells'] ?? []))),
            'zone' => $shop->timezone.' · '.self::symbol((string) $shop->currency),
        ];
    }

    protected function checkMore(): void
    {
        if (mb_strlen(trim((string) ($this->data['name'] ?? ''))) < 2) {
            $this->problems['name'] = __('admin::shop_settings.general.name_short');
        }
    }

    protected function saveMore(): void
    {
        $languages = array_values(array_unique(array_filter((array) ($this->data['languages'] ?? []), fn ($l): bool => isset(self::LANGUAGES[$l]))));
        $languages = $languages === [] ? [(string) $this->shop()->content_locale] : $languages;
        $sells = array_values(array_unique(array_filter(array_map(fn ($s): string => mb_substr((string) preg_replace('/^\s+|\s+$/u', '', (string) $s), 0, 60), (array) ($this->data['sells'] ?? [])), fn (string $s): bool => $s !== '')));

        $this->shop()->forceFill([
            'name' => mb_substr(trim((string) $this->data['name']), 0, 120),
            'content_locale' => $languages[0],
            'profile' => [
                'kind' => in_array($this->data['kind'] ?? null, self::KINDS, true) ? $this->data['kind'] : 'store',
                'languages' => array_slice($languages, 1),
                'sells' => array_slice($sells, 0, 12),
            ],
        ])->save();
    }

    public static function symbol(string $currency): string
    {
        return ['ILS' => '₪', 'USD' => '$', 'EUR' => '€', 'GBP' => '£'][$currency] ?? $currency;
    }

    private function shop(): Shop
    {
        /** @var Shop $shop */
        $shop = Filament::getTenant();

        return $shop;
    }
}
