<?php

namespace App\Modules\Admin\Panels;

use App\Core\Localization\Locales;
use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Admin\Filament\Merchant\Pages\ShopConnection;
use App\Modules\Admin\Filament\Merchant\Pages\ShopOverview;
use App\Modules\Admin\Http\Middleware\SyncTenantFromPanel;
use App\Modules\Admin\Models\User;
use App\Modules\Admin\Support\Home;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationBuilder;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\HtmlString;

/**
 * The merchant's panel: one shop at a time, the shop's results and approvals.
 *
 * Operators can open it to see exactly what a merchant sees. For them the top bar shows a clear
 * way back to the operator panel, where the plugin, connections, keys and activity live.
 */
final class MerchantPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $panel
            ->id(User::MERCHANT_PANEL)
            ->path('merchant')
            ->tenant(Shop::class, slugAttribute: 'slug')
            ->tenantMiddleware([SyncTenantFromPanel::class], isPersistent: true)
            // Each shop on its own address when the platform has a shop domain: gueta.agents.lets.co.il.
            ->tenantDomain(filled(config('upsell.shop_domain')) ? '{tenant:slug}.'.config('upsell.shop_domain') : null)
            // The panel wears the shop's name, so which store you are in is the first thing on
            // the screen and stays there on every page.
            ->brandName(fn (): string => self::shopName())
            // The design's shell: no Filament top bar and no shop menu in the sidebar; the bar is drawn
            // at the top of the content (merchant-topbar), with the store, its state and the person.
            ->topbar(false)
            ->tenantMenu(false)
            ->userMenu(false)
            ->renderHook(PanelsRenderHook::CONTENT_START, fn (): HtmlString => self::topbar())
            // One flat list of screens; a screen's other pages are its tabs, or the settings side menu.
            ->navigation(fn (NavigationBuilder $builder): NavigationBuilder => MerchantNavigation::build($builder))
            ->renderHook(PanelsRenderHook::PAGE_HEADER_WIDGETS_BEFORE, fn (array $scopes): HtmlString => MerchantNavigation::section($scopes));

        // The Lets Search look on top of the shared one: the drawer's tokens, the mark in ink (merchant.css).
        PanelDefaults::apply($panel, 'Merchant');

        return $panel
            ->brandLogo(fn (): HtmlString => new HtmlString(view('admin::panel.merchant-brand')->render()))
            ->renderHook(PanelsRenderHook::STYLES_AFTER, fn (): HtmlString => PanelTheme::merchant());
    }

    private static function shopName(): string
    {
        $shop = Filament::getTenant();

        return $shop instanceof Shop && $shop->name !== '' ? $shop->name : (string) config('app.name');
    }

    /**
     * The top bar as the design draws it: the store, whether Lets is live in it, the language, the store
     * itself, and the person with their menu. An operator in someone else's store sees that, and the way back.
     */
    private static function topbar(): HtmlString
    {
        $shop = Filament::getTenant();
        $user = Auth::user();

        if (! $shop instanceof Shop || ! $user instanceof User) {
            return new HtmlString('');
        }

        // Whether shoppers see Lets, and where the store is: from the first steps, at most every 5 minutes.
        [$live, $storeUrl] = Cache::remember("merchant-topbar:{$shop->id}", 300, function () use ($shop): array {
            $steps = collect(app(Home::class)->steps($shop))->keyBy('key');

            return [(bool) ($steps->get('on')?->done ?? false), $steps->get('look')?->url];
        });

        $role = $user->shops()->whereKey($shop->getKey())->first()?->pivot?->role;
        $operator = self::viewerIsOperator();

        return new HtmlString(view('admin::panel.merchant-topbar', [
            'shop' => $shop,
            'shops' => $user->shops()->orderBy('name')->get()->map(fn (Shop $s): array => [
                'name' => $s->name,
                'url' => ShopOverview::getUrl(tenant: $s),
                'current' => $s->is($shop),
            ])->all(),
            'live' => $live,
            'storeUrl' => $storeUrl,
            'operator' => $operator,
            'operatorUrl' => url('/'.User::OPERATOR_PANEL),
            'locales' => Locales::supported(),
            'current' => app()->getLocale(),
            'name' => (string) $user->name,
            'email' => (string) $user->email,
            'role' => $operator && $role === null
                ? __('admin::panels.top.operator')
                : (ShopRole::tryFrom((string) $role) ?? ShopRole::Member)->label().' · '.$shop->name,
            'links' => [
                ['label' => __('admin::home.connection'), 'url' => ShopConnection::getUrl(tenant: $shop)],
            ],
            'logoutUrl' => filament()->getLogoutUrl(),
        ])->render());
    }

    private static function viewerIsOperator(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->is_operator;
    }
}
