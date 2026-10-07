<?php

namespace App\Modules\Admin\Filament\Merchant\Pages;

use App\Core\Features\FeatureDefinition;
use App\Core\Modules\ModuleManifest;
use App\Core\Settings\SettingDefinition;
use App\Core\Tenancy\LocksShopToPanelTenant;
use App\Modules\Admin\Filament\Operator\Pages\Configuration as OperatorConfiguration;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * What a shop owner may change about their own widget: whether it shows at all, where on the page
 * it sits, which of the two layouts it uses, what the assistant may answer, and the wording of the
 * WhatsApp strip and the sign-up.
 *
 * The same screen the operator uses, with two things taken away. The shop is the one in the
 * address and cannot be switched, and only the keys named here are on it: a merchant never sees
 * a spending cap, a sync limit or anything about enrichment. The allow-list decides what is read
 * and what may be written, so a field that is not on the screen cannot be saved from it either.
 */
final class DisplaySettings extends OperatorConfiguration
{
    use LocksShopToPanelTenant;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSwatch;

    protected static string|UnitEnum|null $navigationGroup = 'on_page';

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'display';

    public static function getNavigationLabel(): string
    {
        return __('admin::configuration.display_title');
    }

    public function getTitle(): string
    {
        return __('admin::configuration.display_title');
    }

    public function getSubheading(): ?string
    {
        return __('admin::configuration.display_help');
    }

    public function mount(): void
    {
        // The trait also declares mount(); this one wins, and the parent still fills the form.
        $this->lockShopToTenant();
        parent::mount();
    }

    /** @return list<FeatureDefinition> */
    protected function features(?ModuleManifest $module = null): array
    {
        return array_values(array_filter(
            parent::features($module),
            fn (FeatureDefinition $definition): bool => in_array($definition->key(), self::allowed(), true),
        ));
    }

    /** @return list<SettingDefinition> */
    protected function settings(?ModuleManifest $module = null): array
    {
        return array_values(array_filter(
            parent::settings($module),
            fn (SettingDefinition $definition): bool => in_array($definition->key(), self::allowed(), true),
        ));
    }

    /** @return list<string> */
    private static function allowed(): array
    {
        return array_merge(...array_values(OperatorConfiguration::SHOP_GROUPS));
    }
}
