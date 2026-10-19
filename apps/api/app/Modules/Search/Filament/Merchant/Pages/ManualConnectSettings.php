<?php

namespace App\Modules\Search\Filament\Merchant\Pages;

use App\Core\Facades\Settings;
use App\Core\Filament\EditsShopSettings;
use App\Core\Settings\InvalidSettingValue;
use App\Core\Tenancy\TenantContext;
use App\Modules\Connections\Enums\ConnectionStatus;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Search\Models\SearchTerm;
use App\Modules\Tenancy\Models\Shop;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Settings · Manual theme connection ("search not showing on the site?"), as the designer's panel lays
 * it out: whether Lets already took over the site's search box (shoppers searched lately), whether the
 * plugin or the Shopify app embed is on, and where to tell Lets the box is: a CSS selector of the shop's
 * own (search.input_selector, empty for the default), or the two markers any theme can carry.
 */
class ManualConnectSettings extends Page
{
    use EditsShopSettings;

    /** Searches from the site in this many days: Lets is on the box. */
    public const SEEN_DAYS = 14;

    public static string $sideGroup = 'connect';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCodeBracket;

    protected static string|UnitEnum|null $navigationGroup = 'settings';

    protected static ?int $navigationSort = 100;

    protected static ?string $slug = 'settings/manual';

    protected string $view = 'search::merchant.settings.manual';

    public static function getNavigationLabel(): string
    {
        return __('search::shop_settings.manual.nav');
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

    /** The shop's own selector only: empty means the default, which every theme's usual box matches. */
    protected function loadMore(): void
    {
        $this->data['selector'] = (string) (Settings::overrideFor('search.input_selector', $this->shopId()) ?? '');
    }

    protected function checkMore(): void
    {
        $selector = $this->selector();

        if ($selector === '') {
            return;
        }

        try {
            Settings::normalize('search.input_selector', $selector);
        } catch (InvalidSettingValue) {
            $this->problems['selector'] = __('search::shop_settings.manual.selector_long');
        }
    }

    protected function saveMore(): void
    {
        $selector = $this->selector();
        $selector === '' ? Settings::clear('search.input_selector', $this->shopId()) : Settings::set('search.input_selector', $selector, $this->shopId());
    }

    protected function saved(): void
    {
        $this->loadMore();
    }

    /**
     * What is known about the site: its platform, whether the plugin answered, its version and when it
     * was last checked, and whether shoppers searched through Lets lately.
     *
     * @return array{platform: string, connected: bool, version: ?string, checked: ?string, seen: bool}
     */
    public function status(): array
    {
        /** @var Shop $shop */
        $shop = Filament::getTenant();

        return app(TenantContext::class)->run($shop->id, function () use ($shop): array {
            $connection = StoreConnection::query()->latest('updated_at')->first();
            $version = $connection === null ? null : data_get($connection->site_info, 'plugin.version');

            return [
                'platform' => (string) ($shop->platform->value ?? $connection?->platform ?? 'woocommerce'),
                'connected' => $connection !== null && $connection->status === ConnectionStatus::Connected,
                'version' => is_string($version) && $version !== '' ? $version : null,
                'checked' => $connection?->last_checked_at?->diffForHumans(),
                'seen' => SearchTerm::query()->whereDate('day', '>=', now()->subDays(self::SEEN_DAYS)->toDateString())->sum('searches') > 0,
            ];
        });
    }

    private function selector(): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) ($this->data['selector'] ?? '')));
    }
}
