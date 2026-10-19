<?php

namespace App\Modules\Widget\Filament\Merchant\Pages;

use App\Core\Facades\Features;
use App\Core\Filament\EditsShopSettings;
use App\Core\Settings\SettingRegistry;
use App\Modules\Widget\Support\PageCache;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Settings · The product page block, as the designer's panel lays it out: where it appears, how many
 * products a row holds, the popularity tag and the store's promises, and which parts it shows. The
 * master switch is the two places together: off turns both off, on with both off turns both on.
 * Saving starts a new generation of the shop's cached pages, so shoppers see it within minutes.
 */
class ProductWidgetSettings extends Page
{
    use EditsShopSettings;

    /** The parts of the block, in the design's order, each its widget.show_* flag. */
    public const PARTS = ['specs', 'highlights', 'position', 'complement', 'family', 'alternatives', 'on_sale', 'good_for', 'guides', 'article_products'];

    public static string $sideGroup = 'shoppers';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static string|UnitEnum|null $navigationGroup = 'settings';

    protected static ?int $navigationSort = 40;

    protected static ?string $slug = 'settings/product-widget';

    protected string $view = 'widget::merchant.settings.product-widget';

    public static function getNavigationLabel(): string
    {
        return __('widget::shop_settings.widget.title');
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
            'on_products' => 'feature:widget.on_products',
            'on_content' => 'feature:widget.on_content',
            'max_products' => 'setting:widget.max_products',
            'popularity' => 'feature:widget.popularity',
            'promises' => 'feature:widget.promises',
        ];
    }

    protected function loadMore(): void
    {
        $shop = $this->shopId();

        $this->data['on'] = (bool) $this->data['on_products'] || (bool) $this->data['on_content'];
        $this->data['parts'] = array_values(array_filter(self::PARTS, fn (string $part): bool => Features::enabled("widget.show_{$part}", $shop)));
    }

    protected function checkMore(): void
    {
        // The master switch is the two places together.
        if (! ($this->data['on'] ?? false)) {
            $this->data['on_products'] = false;
            $this->data['on_content'] = false;
        } elseif (! ($this->data['on_products'] ?? false) && ! ($this->data['on_content'] ?? false)) {
            $this->data['on_products'] = true;
            $this->data['on_content'] = true;
        }
    }

    protected function saveMore(): void
    {
        $shop = $this->shopId();
        $chosen = array_map('strval', (array) ($this->data['parts'] ?? []));

        foreach (self::PARTS as $part) {
            $key = "widget.show_{$part}";
            Features::clearOverride($key, $shop);
            if (Features::enabled($key, $shop) !== in_array($part, $chosen, true)) {
                Features::override($key, in_array($part, $chosen, true), $shop);
            }
        }
    }

    protected function saved(): void
    {
        PageCache::forgetShop($this->shopId());
        $this->data['on'] = (bool) $this->data['on_products'] || (bool) $this->data['on_content'];
    }

    /** @return array{0: int, 1: int} the bounds of widget.max_products */
    public function maxProductsBounds(): array
    {
        $definition = app(SettingRegistry::class)->get('widget.max_products');

        return [(int) $definition->min, (int) $definition->max];
    }
}
