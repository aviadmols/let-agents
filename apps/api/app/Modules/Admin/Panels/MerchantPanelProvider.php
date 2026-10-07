<?php

namespace App\Modules\Admin\Panels;

use App\Modules\Admin\Http\Middleware\SyncTenantFromPanel;
use App\Modules\Admin\Models\User;
use App\Modules\Tenancy\Models\Shop;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
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
            ->navigationGroups(PanelNavigation::groups(PanelNavigation::MERCHANT))
            ->tenant(Shop::class, slugAttribute: 'slug')
            ->tenantMiddleware([SyncTenantFromPanel::class], isPersistent: true)
            // The panel wears the shop's name, so which store you are in is the first thing on
            // the screen and stays there on every page.
            ->brandName(fn (): string => self::shopName())
            ->userMenuItems([
                'operator_panel' => Action::make('operatorPanel')
                    ->label(fn (): string => __('admin::panels.switch.operator'))
                    ->icon(Heroicon::OutlinedCog6Tooth)
                    ->url(fn (): string => url('/'.User::OPERATOR_PANEL))
                    ->visible(fn (): bool => self::viewerIsOperator()),
            ])
            ->renderHook(PanelsRenderHook::TOPBAR_END, fn (): HtmlString => self::operatorShortcut())
            ->renderHook(PanelsRenderHook::PAGE_START, fn (): HtmlString => self::whoseShop());

        return PanelDefaults::apply($panel, 'Merchant');
    }

    private static function shopName(): string
    {
        $shop = Filament::getTenant();

        return $shop instanceof Shop && $shop->name !== '' ? $shop->name : (string) config('app.name');
    }

    /**
     * Whose shop this is, above the page itself. A shop owner is told plainly that this is their
     * store; an operator who opened someone else's is told plainly that it is not, because the
     * panel otherwise looks exactly the same from both chairs.
     */
    private static function whoseShop(): HtmlString
    {
        $shop = Filament::getTenant();

        if (! $shop instanceof Shop) {
            return new HtmlString('');
        }

        return new HtmlString(Blade::render(
            <<<'BLADE'
            <div @class([
                'fi-section mb-4 flex items-center gap-3 rounded-xl px-4 py-2.5 text-sm ring-1',
                'bg-warning-50 text-warning-700 ring-warning-600/20 dark:bg-warning-500/10 dark:text-warning-300' => $operator,
                'bg-gray-50 text-gray-600 ring-gray-950/5 dark:bg-white/5 dark:text-gray-300 dark:ring-white/10' => ! $operator,
            ]) data-shop-banner="{{ $slug }}">
                <x-filament::icon :icon="$operator ? 'heroicon-o-eye' : 'heroicon-o-building-storefront'" class="h-5 w-5 shrink-0" />
                <span>{{ $message }}</span>
            </div>
            BLADE,
            [
                'operator' => self::viewerIsOperator(),
                'slug' => (string) $shop->slug,
                'message' => self::viewerIsOperator()
                    ? __('admin::panels.account.as_operator', ['shop' => $shop->name])
                    : __('admin::panels.account.viewing').' · '.$shop->name,
            ],
        ));
    }

    private static function viewerIsOperator(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->is_operator;
    }

    private static function operatorShortcut(): HtmlString
    {
        if (! self::viewerIsOperator()) {
            return new HtmlString('');
        }

        return new HtmlString(Blade::render(
            '<x-filament::button tag="a" :href="$url" size="sm" color="gray" icon="heroicon-o-cog-6-tooth" data-operator-shortcut>{{ $label }}</x-filament::button>',
            ['url' => url('/'.User::OPERATOR_PANEL), 'label' => __('admin::panels.switch.operator')],
        ));
    }
}
