<?php

namespace App\Modules\Admin\Filament\Merchant\Pages;

use App\Core\Facades\Settings;
use App\Core\Settings\SettingRegistry;
use App\Core\Tenancy\TenantContext;
use App\Core\Usage\MonthlyUsage;
use App\Modules\Admin\Contracts\ConnectionFacts;
use App\Modules\Admin\Contracts\HomeCard;
use App\Modules\Admin\Contracts\StorePlugin;
use App\Modules\Admin\Mail\TeamNoticeMail;
use App\Modules\Admin\Models\User;
use App\Modules\Admin\Support\Home;
use App\Modules\Admin\Support\ShopLeavingNotices;
use App\Modules\Tenancy\Enums\ShopPlatform;
use App\Modules\Tenancy\Models\Plan;
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
    /** A monthly setting at this or above is no limit (its default). */
    private const NO_LIMIT = 1000000;

    /** The bar turns amber from this share of the month's allowance. */
    private const WARN_PERCENT = 80;

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
            'plan_lines' => $shop->currentPlan()?->lines() ?? [],
            'allowance' => $this->allowance($shop),
            'plans_url' => is_string($facts['plans_url'] ?? null) ? $facts['plans_url'] : null,
            'plans' => $this->plans($shop, (array) ($facts['plan_urls'] ?? [])),
            'has_token' => (bool) ($facts['has_token'] ?? false),
        ];
    }

    /**
     * The plans side by side: each one's price and what it gives, the shop's own marked, and the way to it. An upgrade or
     * a downgrade, by price against the shop's plan; a Shopify store approves it in Shopify's billing (plan_urls), any
     * other store asks the team (askPlan).
     *
     * @param  array<string, string>  $urls
     * @return list<array{code: string, name: string, price: string, lines: list<string>, current: bool, move: string|null, url: string|null}>
     */
    private function plans(Shop $shop, array $urls): array
    {
        $current = $shop->cancelled_at === null && ! $shop->leaving() ? $shop->currentPlan() : null;

        return Plan::query()->offered()->get()->map(fn (Plan $plan): array => [
            'code' => $plan->code,
            'name' => $plan->name,
            'price' => '$'.number_format((float) $plan->price()),
            'lines' => $plan->lines(),
            'current' => $current?->code === $plan->code,
            'move' => $current === null ? 'choose' : match ($plan->price() <=> $current->price()) {
                1 => 'upgrade', -1 => 'downgrade', default => null
            },
            'url' => is_string($urls[$plan->code] ?? null) ? $urls[$plan->code] : null,
        ])->all();
    }

    /** A store no platform bills asks the team to move it to a plan: the team is told, and switches it. */
    public function askPlan(string $code): void
    {
        $shop = $this->shop();
        $plan = Plan::query()->offered()->where('code', $code)->first();
        $user = Auth::user();

        if ($plan === null || ! $user instanceof User || ! $user->managesShop($shop)) {
            Notification::make()->warning()->title(__('admin::shop_settings.owners_only'))->send();

            return;
        }

        ShopLeavingNotices::team($shop, TeamNoticeMail::PLAN_ASKED, (string) $user->email, true, $plan->name);
        Notification::make()->success()->title(__('admin::shop_settings.connection.plan_asked', ['plan' => $plan->name]))->send();
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

    /**
     * This month's answers and photo searches against the shop's plan, where the plan counts them.
     *
     * @return list<array{label: string, used: int, limit: int, percent: int, tone: string}>
     */
    private function allowance(Shop $shop): array
    {
        if ($shop->currentPlan() === null) {
            return [];
        }

        $rows = [];

        foreach (['answers' => 'assistant.answers_per_month', 'photos' => 'search.photos_per_month'] as $meter => $key) {
            $limit = app(SettingRegistry::class)->has($key) ? (int) Settings::get($key, $shop->id) : 0;

            if ($limit <= 0 || $limit >= self::NO_LIMIT) {
                continue;
            }

            $used = app(MonthlyUsage::class)->used($shop->id, $meter);
            $rows[] = [
                'label' => __('admin::shop_settings.connection.allowance_'.$meter),
                'used' => $used,
                'limit' => $limit,
                'percent' => (int) min(100, round($used * 100 / $limit)),
                'tone' => $used >= $limit ? 'bad' : ($used * 100 >= $limit * self::WARN_PERCENT ? 'warn' : ''),
            ];
        }

        return $rows;
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
