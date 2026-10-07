<?php

namespace App\Modules\Admin\Panels;

use App\Core\Localization\Locales;
use App\Core\Modules\ModuleRepository;
use App\Modules\Admin\Http\Middleware\ApplyAdminLocale;
use Filament\Actions\Action;
use Filament\FontProviders\GoogleFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * What both admin panels share: look, middleware, the language switch, and discovery of
 * Filament components from every enabled module.
 *
 * A module adds screens to a panel by placing them in
 *   app/Modules/{Name}/Filament/{Operator|Merchant}/{Resources|Pages|Widgets}
 * and nothing else. No panel provider needs editing.
 */
final class PanelDefaults
{
    /** @param list<class-string> $extraMiddleware appended after the locale middleware */
    public static function apply(Panel $panel, string $moduleDirectory, array $extraMiddleware = []): Panel
    {
        $panel
            ->login()
            ->brandName((string) config('app.name'))
            ->font('Rubik', url: PanelTheme::FONT_URL, provider: GoogleFontProvider::class)
            ->maxContentWidth(Width::Full)
            ->colors([
                'primary' => Color::hex('#7e22ce'),
                'success' => Color::hex('#16A34A'),
                'danger' => Color::hex('#DC2626'),
                'warning' => Color::hex('#D97706'),
                'info' => Color::hex('#2563EB'),
                'gray' => Color::Neutral,
            ])
            ->userMenuItems([
                'locale' => self::localeSwitchAction(),
            ])
            ->renderHook(PanelsRenderHook::STYLES_AFTER, fn (): HtmlString => PanelTheme::styles())
            ->renderHook(PanelsRenderHook::AUTH_LOGIN_FORM_AFTER, fn (): HtmlString => self::loginLocaleLinks())
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                // After the session starts (it reads the user and the session choice) and
                // before anything renders.
                ApplyAdminLocale::class,
                DispatchServingFilamentEvent::class,
                ...$extraMiddleware,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            // Livewire updates (saving a form, pressing an action) are separate requests that skip
            // the panel's route middleware. Without these, a button press would run in the wrong
            // language and, in the operator panel, without cross-shop access.
            ->persistentMiddleware([
                ApplyAdminLocale::class,
                ...$extraMiddleware,
            ]);

        return self::discoverModuleComponents($panel, $moduleDirectory);
    }

    public static function discoverModuleComponents(Panel $panel, string $moduleDirectory): Panel
    {
        foreach (app(ModuleRepository::class)->enabled() as $module) {
            $base = "Filament/{$moduleDirectory}";
            $namespace = "{$module->namespace}\\Filament\\{$moduleDirectory}";

            if (is_dir($dir = $module->path("{$base}/Resources"))) {
                $panel->discoverResources(in: $dir, for: "{$namespace}\\Resources");
            }

            if (is_dir($dir = $module->path("{$base}/Pages"))) {
                $panel->discoverPages(in: $dir, for: "{$namespace}\\Pages");
            }

            if (is_dir($dir = $module->path("{$base}/Widgets"))) {
                $panel->discoverWidgets(in: $dir, for: "{$namespace}\\Widgets");
            }
        }

        return $panel;
    }

    private static function localeSwitchAction(): Action
    {
        return Action::make('switchLocale')
            ->label(fn (): string => __('admin::panels.locale.switch_to', ['language' => __('admin::panels.locale.names.'.self::otherLocale())]))
            ->icon(Heroicon::OutlinedLanguage)
            ->url(fn (): string => route('admin.locale', ['locale' => self::otherLocale()]));
    }

    /** With two admin languages the switch is a toggle; with more it would become a list. */
    private static function otherLocale(): string
    {
        $others = array_values(array_diff(Locales::supported(), [app()->getLocale()]));

        return $others[0] ?? Locales::fallback();
    }

    private static function loginLocaleLinks(): HtmlString
    {
        $links = [];

        foreach (Locales::supported() as $locale) {
            $label = e(__("admin::panels.locale.names.{$locale}", [], $locale));
            $url = e(route('admin.locale', ['locale' => $locale]));
            $current = $locale === app()->getLocale();

            $links[] = $current
                ? "<span class=\"font-semibold\" aria-current=\"true\">{$label}</span>"
                : "<a href=\"{$url}\" class=\"text-primary-600 hover:underline\" lang=\"{$locale}\">{$label}</a>";
        }

        return new HtmlString('<nav class="mt-4 flex justify-center gap-4 text-sm" aria-label="Language">'.implode('', $links).'</nav>');
    }
}
