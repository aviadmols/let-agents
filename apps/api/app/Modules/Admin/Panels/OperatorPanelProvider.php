<?php

namespace App\Modules\Admin\Panels;

use App\Modules\Admin\Http\Middleware\EnterOperatorScope;
use App\Modules\Admin\Models\User;
use App\Modules\Admin\Support\CurrentShop;
use App\Modules\Tenancy\Models\Shop;
use Filament\Actions\Action;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\HtmlString;

/**
 * The system operator's panel: every shop, every setting, costs, models and prompts.
 *
 * One shop at a time by default. The picker in the top bar decides which, and the choice holds
 * across every screen until it is changed, so what a list shows always belongs to one store.
 */
final class OperatorPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $panel
            ->default()
            ->id(User::OPERATOR_PANEL)
            ->path('operator')
            ->navigationGroups(PanelNavigation::groups(PanelNavigation::OPERATOR))
            ->userMenuItems([
                'merchant_view' => Action::make('merchantView')
                    ->label(fn (): string => __('admin::panels.switch.merchant'))
                    ->icon(Heroicon::OutlinedBuildingStorefront)
                    ->url(fn (): string => url('/'.User::MERCHANT_PANEL)),
            ])
            ->renderHook(PanelsRenderHook::TOPBAR_START, fn (): HtmlString => self::shopPicker());

        return PanelDefaults::apply($panel, 'Operator', [EnterOperatorScope::class]);
    }

    /**
     * The shop this panel is working inside. A plain form, so switching survives a full page load
     * and the choice is in the session before the next screen builds its queries.
     */
    private static function shopPicker(): HtmlString
    {
        return new HtmlString(Blade::render(
            <<<'BLADE'
            <div class="flex items-center gap-3" data-shop-bar>
            <form method="POST" action="{{ route('admin.shop') }}" class="flex items-center gap-2">
                @csrf
                <input type="hidden" name="back" value="{{ $back }}">
                <span @class([
                    'inline-flex h-2 w-2 shrink-0 rounded-full',
                    'bg-primary-500' => $chosen,
                    'bg-gray-300 dark:bg-gray-600' => ! $chosen,
                ])></span>
                <label for="operator-shop" class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</label>
                <select
                    id="operator-shop"
                    name="shop"
                    onchange="this.form.requestSubmit()"
                    data-operator-shop
                    class="fi-select-input block rounded-lg border-none bg-white py-1.5 pe-8 ps-3 text-sm font-medium text-gray-950 shadow-sm ring-1 ring-gray-950/10 focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/20"
                >
                    @foreach ($options as $value => $name)
                        <option value="{{ $value }}" @selected($value === $current)>{{ $name }}</option>
                    @endforeach
                </select>
            </form>
            @if ($viewAs)
                <x-filament::button tag="a" :href="$viewAs" size="sm" color="gray" icon="heroicon-o-eye" data-view-as-shop>{{ $viewAsLabel }}</x-filament::button>
            @endif
            </div>
            BLADE,
            [
                'options' => CurrentShop::options(),
                'current' => CurrentShop::effective() ?? CurrentShop::EVERY,
                'chosen' => CurrentShop::effective() !== null,
                'label' => __('admin::panels.shop.label'),
                'back' => '/'.ltrim(Request::path(), '/'),
                'viewAs' => self::viewAsUrl(),
                'viewAsLabel' => __('admin::panels.view_as_shop'),
            ],
        ));
    }

    /** The chosen shop's own panel, as its manager sees it; null while every shop is shown. */
    private static function viewAsUrl(): ?string
    {
        $id = CurrentShop::effective();
        $slug = $id === null ? null : Shop::query()->whereKey($id)->value('slug');

        return $slug === null ? null : url('/'.User::MERCHANT_PANEL.'/'.$slug);
    }
}
