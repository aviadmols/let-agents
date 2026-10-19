<?php

namespace App\Modules\Admin\Filament\Merchant\Pages;

use App\Core\Localization\Locales;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Actions\InviteToShop;
use App\Modules\Admin\Actions\RemoveFromShop;
use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Admin\Models\ShopInvitation;
use App\Modules\Admin\Models\User;
use App\Modules\Tenancy\Models\Shop;
use BackedEnum;
use Carbon\CarbonInterface;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Throwable;
use UnitEnum;

/**
 * Settings · Users, as the designer's panel lays it out: who signs in to the shop's panel (each with
 * their role and when they last came in, invitations still waiting), and a form to invite someone as an
 * owner, the team or a viewer. The invited person gets an email with a link and sets their own password.
 * Only an owner invites and removes.
 */
class UsersSettings extends Page
{
    public static string $sideGroup = 'account';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'settings';

    protected static ?int $navigationSort = 120;

    protected static ?string $slug = 'settings/users';

    protected string $view = 'admin::merchant.settings.users';

    /** @var array{name: string, email: string, role: string, locale: string} the invitation form */
    public array $data = ['name' => '', 'email' => '', 'role' => 'member', 'locale' => 'he'];

    /** @var array<string, string> what was wrong, by field */
    public array $problems = [];

    public static function getNavigationLabel(): string
    {
        return __('admin::shop_settings.users.title');
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
        $this->data['locale'] = Locales::isSupported(app()->getLocale()) ? app()->getLocale() : 'he';
    }

    /**
     * The shop's people and the invitations still waiting, owners first.
     *
     * @return list<array{key: string, kind: string, id: int|string, initial: string, name: string, line: string, role: ShopRole, owner: bool, removable: bool, expired?: bool}>
     */
    public function people(): array
    {
        $shop = $this->shop();
        $me = Auth::id();
        $owners = $shop->belongsToMany(User::class, 'shop_user')->wherePivot('role', ShopRole::Owner->value)->count();
        $rows = [];

        foreach ($shop->belongsToMany(User::class, 'shop_user')->withPivot('role')->orderBy('name')->get() as $user) {
            /** @var User $user */
            $role = ShopRole::tryFrom((string) $user->getRelationValue('pivot')?->role) ?? ShopRole::Member;
            $rows[] = [
                'key' => 'u'.$user->id,
                'kind' => 'user',
                'id' => $user->id,
                'initial' => mb_substr($user->name !== '' ? $user->name : $user->email, 0, 1),
                'name' => $user->name !== '' ? $user->name : $user->email,
                'line' => $role->label().($role === ShopRole::Owner ? '' : ' · '.$role->description()).' · '.$this->seen($user->last_login_at),
                'role' => $role,
                'owner' => $role === ShopRole::Owner,
                // Not yourself, and never the last owner.
                'removable' => $user->id !== $me && ($role !== ShopRole::Owner || $owners > 1),
            ];
        }

        usort($rows, fn (array $a, array $b): int => [$b['owner'], $a['name']] <=> [$a['owner'], $b['name']]);

        $invited = app(TenantContext::class)->run((string) $shop->id, fn () => ShopInvitation::query()->pending()->latest('sent_at')->get());

        foreach ($invited as $invitation) {
            /** @var ShopInvitation $invitation */
            $rows[] = [
                'key' => 'i'.$invitation->id,
                'kind' => 'invitation',
                'id' => $invitation->id,
                'initial' => mb_substr($invitation->name ?: $invitation->email, 0, 1),
                'name' => $invitation->email,
                'line' => $invitation->expired()
                    ? __('admin::shop_settings.users.expired')
                    : __('admin::shop_settings.users.sent', ['when' => ($invitation->sent_at ?? $invitation->created_at)?->locale(app()->getLocale())->diffForHumans()]),
                'role' => $invitation->role,
                'owner' => false,
                'removable' => false,
                'expired' => $invitation->expired(),
            ];
        }

        return $rows;
    }

    /** @return array<string, array{title: string, text: string}> the roles, as option cards */
    public function roles(): array
    {
        $roles = [];
        foreach (ShopRole::cases() as $role) {
            $roles[$role->value] = ['title' => $role->label(), 'text' => $role->description()];
        }

        return $roles;
    }

    public function problem(string $field): ?string
    {
        return $this->problems[$field] ?? null;
    }

    public function canManage(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->managesShop($this->shop());
    }

    public function invite(): void
    {
        if (! $this->owner()) {
            return;
        }

        $this->problems = [];
        $email = mb_strtolower(trim((string) ($this->data['email'] ?? '')));
        $name = trim((string) ($this->data['name'] ?? ''));
        $role = ShopRole::tryFrom((string) ($this->data['role'] ?? '')) ?? ShopRole::Member;
        $locale = Locales::isSupported((string) ($this->data['locale'] ?? '')) ? (string) $this->data['locale'] : 'he';

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->problems['email'] = __('admin::shop_settings.users.email_wrong');
        } elseif ($this->shop()->belongsToMany(User::class, 'shop_user')->whereRaw('lower(email) = ?', [$email])->exists()) {
            $this->problems['email'] = __('admin::shop_settings.users.already');
        }

        if ($this->problems !== []) {
            Notification::make()->warning()->title(__('admin::shop_settings.problems'))->send();

            return;
        }

        try {
            app(InviteToShop::class)->handle($this->shop(), $this->me(), $email, $name, $role, $locale);
        } catch (Throwable $e) {
            report($e);
            Notification::make()->danger()->title(__('admin::shop_settings.users.not_sent'))->send();

            return;
        }

        $this->data = ['name' => '', 'email' => '', 'role' => ShopRole::Member->value, 'locale' => $locale];
        Notification::make()->success()->title(__('admin::shop_settings.users.invited'))->send();
    }

    public function resend(string $id): void
    {
        if (! $this->owner()) {
            return;
        }

        $invitation = app(TenantContext::class)->run((string) $this->shop()->id, fn (): ?ShopInvitation => ShopInvitation::query()->pending()->find($id));

        if ($invitation === null) {
            return;
        }

        try {
            app(InviteToShop::class)->resend($invitation, $this->me());
        } catch (Throwable $e) {
            report($e);
            Notification::make()->danger()->title(__('admin::shop_settings.users.not_sent'))->send();

            return;
        }

        Notification::make()->success()->title(__('admin::shop_settings.users.resent'))->send();
    }

    public function remove(int $id): void
    {
        if (! $this->owner() || $id === Auth::id()) {
            return;
        }

        $user = $this->shop()->belongsToMany(User::class, 'shop_user')->whereKey($id)->first();

        if (! $user instanceof User || ! app(RemoveFromShop::class)->handle($this->shop(), $user)) {
            Notification::make()->warning()->title(__('admin::shop_settings.users.not_removed'))->send();

            return;
        }

        Notification::make()->success()->title(__('admin::shop_settings.users.removed', ['name' => $user->name !== '' ? $user->name : $user->email]))->send();
    }

    /** Whether the person may manage the people; says so when not. */
    private function owner(): bool
    {
        if ($this->canManage()) {
            return true;
        }

        Notification::make()->warning()->title(__('admin::shop_settings.owners_only'))->send();

        return false;
    }

    private function seen(?CarbonInterface $at): string
    {
        $t = fn (string $key, array $params = []): string => __('admin::shop_settings.users.'.$key, $params);

        return match (true) {
            $at === null => $t('never'),
            $at->isToday() => $t('today'),
            $at->isYesterday() => $t('yesterday'),
            default => $t('days', ['when' => $at->locale(app()->getLocale())->diffForHumans()]),
        };
    }

    private function me(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    private function shop(): Shop
    {
        /** @var Shop $shop */
        $shop = Filament::getTenant();

        return $shop;
    }
}
