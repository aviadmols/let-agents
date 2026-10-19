<?php

namespace App\Modules\Admin\Filament\Merchant\Pages;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Contracts\ConnectionFacts;
use App\Modules\Admin\Contracts\HomeCard;
use App\Modules\Admin\Contracts\StorePlugin;
use App\Modules\Admin\Models\User;
use App\Modules\Admin\Support\Home;
use App\Modules\Tenancy\Enums\ShopPlatform;
use App\Modules\Tenancy\Models\Shop;
use BackedEnum;
use Carbon\CarbonInterface;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;
use UnitEnum;

/**
 * Settings · Connection and plan, as the designer's panel lays it out: whether the store is connected and
 * how (the plugin and its version, or the Shopify app), its address, when the catalogue was last read,
 * the plan, and how much of the month's AI allowance is used. A WordPress store also gets its plugin
 * here: the zip to install, and a connection token to paste into the plugin's settings, shown once.
 */
class ConnectionSettings extends Page
{
    public static string $sideGroup = 'connect';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;

    protected static string|UnitEnum|null $navigationGroup = 'settings';

    protected static ?int $navigationSort = 110;

    protected static ?string $slug = 'settings/connection';

    protected string $view = 'admin::merchant.settings.connection';

    /** The token just made, shown once: it is gone with the page. */
    public ?string $token = null;

    public static function getNavigationLabel(): string
    {
        return __('admin::shop_settings.connection.title');
    }

    public function getTitle(): string
    {
        return __('admin::shop_settings.title');
    }

    public function getSubheading(): ?string
    {
        return __('admin::shop_settings.subheading');
    }

    /** @return array<string, mixed> what the card shows */
    public function details(): array
    {
        $shop = $this->shop();
        $facts = $this->facts($shop);
        $woo = $shop->platform === ShopPlatform::WooCommerce;
        $t = fn (string $key, array $params = []): string => __('admin::shop_settings.connection.'.$key, $params);

        $platform = $woo
            ? (($facts['plugin_version'] ?? null) !== null ? $t('woo', ['version' => $facts['plugin_version']]) : $t('woo_waiting'))
            : (($facts['app_installed'] ?? false) ? $t('shopify') : $t('shopify_missing'));

        return [
            'connected' => (bool) ($facts['connected'] ?? false),
            'woo' => $woo,
            'platform' => $platform,
            'domain' => $shop->domain,
            'synced' => ($facts['last_sync'] ?? null) instanceof CarbonInterface ? $facts['last_sync']->locale(app()->getLocale())->diffForHumans() : $t('never_synced'),
            'plan' => $this->plan($facts),
            'usage' => $this->usage($shop),
            'has_token' => (bool) ($facts['has_token'] ?? false),
        ];
    }

    /** @return array{version: string, filename: string}|null the plugin zip a WordPress store can download */
    public function package(): ?array
    {
        $package = $this->plugin()?->package();

        return $package === null ? null : ['version' => $package['version'], 'filename' => $package['filename']];
    }

    public function canEdit(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->canEditShop($this->shop());
    }

    /** Only an owner (or an operator) makes a token: it decides which store Lets reads. */
    public function canConnect(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->managesShop($this->shop());
    }

    public function downloadPlugin(): ?BinaryFileResponse
    {
        $package = $this->plugin()?->package();

        if (! $this->canEdit() || $package === null) {
            Notification::make()->warning()->title(__('admin::shop_settings.connection.no_package'))->send();

            return null;
        }

        return response()->download($package['path'], $package['filename'], ['Content-Type' => 'application/zip', 'Cache-Control' => 'no-store']);
    }

    public function createToken(): void
    {
        $plugin = $this->plugin();

        if (! $this->canConnect() || $plugin === null || $this->shop()->platform !== ShopPlatform::WooCommerce) {
            Notification::make()->warning()->title(__('admin::shop_settings.owners_only'))->send();

            return;
        }

        $this->token = $plugin->issueToken($this->shop());
        Home::forget($this->shop());
    }

    public function checkConnection(): void
    {
        $result = null;

        try {
            $result = $this->canEdit() ? $this->plugin()?->check($this->shop()) : null;
        } catch (Throwable $e) {
            report($e);
        }

        Home::forget($this->shop());
        $note = Notification::make()->title(__('admin::shop_settings.connection.'.match ($result) {
            true => 'check_good',
            false => 'check_bad',
            null => 'check_none',
        }));
        ($result === true ? $note->success() : $note->warning())->send();
    }

    /** @param array<string, mixed> $facts */
    private function plan(array $facts): string
    {
        $t = fn (string $key, array $params = []): string => __('admin::shop_settings.connection.'.$key, $params);
        $date = fn (CarbonInterface $d): string => $d->format('j.n.Y');

        if (($facts['plan_waiting'] ?? false) === true) {
            return $t('plan_waiting');
        }

        $name = is_string($facts['plan'] ?? null) && $facts['plan'] !== '' ? $facts['plan'] : $t('plan_default');

        return match (true) {
            ($facts['trial_ends'] ?? null) instanceof CarbonInterface => $t('plan_trial', ['plan' => $name, 'date' => $date($facts['trial_ends'])]),
            ($facts['renews'] ?? null) instanceof CarbonInterface => $t('plan_renews', ['plan' => $name, 'date' => $date($facts['renews'])]),
            default => $name,
        };
    }

    /** @return array{percent: int, tone: string}|null the month's AI allowance, as the home's card has it */
    private function usage(Shop $shop): ?array
    {
        $card = collect(app(Home::class)->cards($shop, now()->subDays(30), now()))->first(fn (HomeCard $c): bool => $c->key === 'ai');

        return $card === null ? null : ['percent' => (int) $card->bar, 'tone' => $card->tone];
    }

    /** @return array<string, mixed> */
    private function facts(Shop $shop): array
    {
        $facts = [];

        foreach (app()->tagged(ConnectionFacts::TAG) as $source) {
            try {
                if ($source instanceof ConnectionFacts) {
                    $facts = array_merge($facts, array_filter(app(TenantContext::class)->run((string) $shop->id, fn (): array => $source->facts($shop)), fn ($v): bool => $v !== null));
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $facts;
    }

    private function plugin(): ?StorePlugin
    {
        return app()->bound(StorePlugin::class) ? app(StorePlugin::class) : null;
    }

    private function shop(): Shop
    {
        /** @var Shop $shop */
        $shop = Filament::getTenant();

        return $shop;
    }
}
