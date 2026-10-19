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
 * What a shop owner may change about the drawer in their store: on which pages it shows, which
 * panels it holds, what the assistant may answer, and the wording of the WhatsApp strip and the
 * sign-up. There is one design, so there is no look or placement to choose.
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

    protected static string|UnitEnum|null $navigationGroup = 'settings';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'display';

    /**
     * Out of the settings menu: what it held (where the drawer shows, its panels, the assistant, WhatsApp,
     * the sign-up) has its own settings page each now. The address stays for links already given.
     */
    protected static bool $shouldRegisterNavigation = false;

    /**
     * Which area is open, kept in the component only. The operator's screen writes it into the
     * address; inside the Shopify admin the address belongs to App Bridge, which syncs it with the
     * admin's, and the screen stopped answering. A merchant reopens on the first area.
     */
    public string $area = '';

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
        // The search has its own screen, "Search display", with a preview.
        return array_merge(...array_values(array_diff_key(OperatorConfiguration::SHOP_GROUPS, ['search' => true])));
    }
}
