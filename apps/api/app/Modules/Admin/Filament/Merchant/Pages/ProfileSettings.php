<?php

namespace App\Modules\Admin\Filament\Merchant\Pages;

use App\Core\Localization\Locales;
use App\Modules\Admin\Http\Middleware\ApplyAdminLocale;
use App\Modules\Admin\Models\User;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use UnitEnum;

/**
 * Settings · My profile, as the designer's panel lays it out: the person's name and email, the panel's
 * language, its display mode (kept on the person, so the panel opens in it on any device, and the top
 * bar's switch changes the same), and a new password, given with the current one.
 */
class ProfileSettings extends Page
{
    public static string $sideGroup = 'account';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserCircle;

    protected static string|UnitEnum|null $navigationGroup = 'settings';

    protected static ?int $navigationSort = 130;

    protected static ?string $slug = 'settings/profile';

    protected string $view = 'admin::merchant.settings.profile';

    /** @var array{name: string, email: string, locale: string, theme: string, current: string, password: string, password_again: string} */
    public array $data = [];

    /** @var array<string, string> */
    public array $problems = [];

    public bool $changingPassword = false;

    public static function getNavigationLabel(): string
    {
        return __('admin::shop_settings.profile.title');
    }

    public function getTitle(): string
    {
        return __('admin::shop_settings.title');
    }

    public function getSubheading(): ?string
    {
        return __('admin::shop_settings.subheading');
    }

    public function mount(): void
    {
        $user = $this->user();

        $this->data = [
            'name' => (string) $user->name,
            'email' => (string) $user->email,
            'locale' => $user->preferredLocale() ?? app()->getLocale(),
            'theme' => $user->preferredTheme() ?? 'system',
            'current' => '',
            'password' => '',
            'password_again' => '',
        ];
    }

    public function problem(string $field): ?string
    {
        return $this->problems[$field] ?? null;
    }

    public function save(): void
    {
        $user = $this->user();
        $this->problems = [];
        $t = fn (string $key, array $params = []): string => __('admin::shop_settings.profile.'.$key, $params);

        $name = trim((string) ($this->data['name'] ?? ''));
        $email = mb_strtolower(trim((string) ($this->data['email'] ?? '')));
        $locale = Locales::isSupported((string) ($this->data['locale'] ?? '')) ? (string) $this->data['locale'] : app()->getLocale();
        $theme = in_array($this->data['theme'] ?? null, User::THEMES, true) ? (string) $this->data['theme'] : 'system';
        $password = (string) ($this->data['password'] ?? '');

        if (mb_strlen($name) < 2) {
            $this->problems['name'] = $t('name_short');
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->problems['email'] = $t('email_wrong');
        } elseif (User::query()->whereKeyNot($user->id)->whereRaw('lower(email) = ?', [$email])->exists()) {
            $this->problems['email'] = $t('email_taken');
        }

        // A new password only when one was typed (a browser may fill the current one by itself), and always with the current one.
        if ($password !== '') {
            if (! Hash::check((string) ($this->data['current'] ?? ''), (string) $user->password)) {
                $this->problems['current'] = $t('current_wrong');
            } elseif (mb_strlen($password) < User::MIN_PASSWORD_LENGTH) {
                $this->problems['password'] = $t('password_short', ['min' => User::MIN_PASSWORD_LENGTH]);
            } elseif ($password !== (string) ($this->data['password_again'] ?? '')) {
                $this->problems['password_again'] = $t('password_mismatch');
            }
        }

        if ($this->problems !== []) {
            Notification::make()->warning()->title(__('admin::shop_settings.problems'))->send();

            return;
        }

        $user->forceFill(['name' => mb_substr($name, 0, 120), 'email' => $email, 'locale' => $locale, 'theme' => $theme]);
        if ($password !== '') {
            $user->password = $password;
        }
        $user->save();

        $languageChanged = $locale !== app()->getLocale();
        session()->put(ApplyAdminLocale::SESSION_KEY, $locale);

        $this->data['current'] = $this->data['password'] = $this->data['password_again'] = '';
        $this->changingPassword = false;

        // The mode applies at once, the same way the top bar's switch does.
        $this->js('window.dispatchEvent(new CustomEvent("theme-changed", { detail: '.json_encode($theme).' }))');

        Notification::make()->success()->title(__('admin::shop_settings.saved_plain', [], $locale))->send();

        // A new language redraws the whole panel in it.
        if ($languageChanged) {
            $this->redirect(static::getUrl(), navigate: false);
        }
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
