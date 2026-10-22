<?php

namespace App\Modules\Admin\Support;

use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Admin\Filament\Merchant\Pages\PrivacySettings;
use App\Modules\Admin\Filament\Merchant\Pages\ProfileSettings;
use App\Modules\Admin\Models\User;
use App\Modules\Tenancy\Models\Shop;
use Closure;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * A viewer ("צפייה") reads the shop's panel and changes nothing. Every press of a button in the panel is a
 * Livewire call; for a viewer, a call goes through only when it just shows something: opening a list,
 * paging, sorting, choosing a period, opening a modal. On the reading screens (home, analytics, sessions,
 * timeline) everything but the known changes goes through; elsewhere only what is on the reading list.
 * Their own profile is theirs to change. A blocked call does nothing and says so.
 *
 * Asked by the Livewire "call" hook (AdminServiceProvider), so no page has to know about roles.
 */
final class ViewerGuard
{
    /** Screens that are for reading (MerchantNavigation keys); the home has no key. */
    public const READING_SCREENS = ['analytics', 'sessions', 'timeline'];

    /** Calls that only show something, anywhere in the panel. */
    public const READING_CALLS = [
        '$refresh', '$set', '$toggle', '__dispatch', '__lazyLoad',
        'open', 'close', 'show', 'hide', 'more', 'pick', 'select', 'filter', 'sortBy', 'setDays', 'narrowTo',
        'toggleGroup', 'toggleInspect', 'showFailed', 'setShow', 'reveal', 'downloadPlugin',
        'mountAction', 'unmountAction', 'gotoPage', 'nextPage', 'previousPage', 'setPage', 'resetPage',
    ];

    /** Calls that change something even on a reading screen. */
    public const CHANGING_CALLS = ['save', 'act', 'callMountedAction', 'markDone', 'undo', 'answer', 'approve', 'reject', 'remove', 'invite', 'resend'];

    /**
     * Livewire's "call" listener: stops a viewer's call that would change the shop.
     *
     * @param  array<int, mixed>  $params
     */
    public static function call(object $component, string $method, array $params, mixed $context, Closure $returnEarly): void
    {
        if (self::allows($component, $method)) {
            return;
        }

        $returnEarly(null);
        Notification::make()->warning()->title(__('admin::shop_settings.read_only'))->send();
    }

    public static function allows(object $component, string $method): bool
    {
        if (Filament::getCurrentPanel()?->getId() !== User::MERCHANT_PANEL || ! self::viewing()) {
            return true;
        }

        if ($component instanceof ProfileSettings) {
            return true;
        }

        // their own account is theirs to delete; the shop's plan and the shop itself are the owner's (PrivacySettings checks)
        if ($component instanceof PrivacySettings && $method === 'deleteMe') {
            return true;
        }

        if (in_array($method, self::CHANGING_CALLS, true)) {
            return false;
        }

        if (in_array($method, self::READING_CALLS, true)) {
            return true;
        }

        return $component instanceof Page && self::reading($component::class);
    }

    /** Whether the signed-in person is only a viewer of the shop the panel is on. */
    public static function viewing(): bool
    {
        $user = Auth::user();
        $shop = Filament::getTenant();

        return $user instanceof User && $shop instanceof Shop && ! $user->is_operator && $user->roleIn($shop) === ShopRole::Viewer;
    }

    /** @param  class-string<Page>  $page */
    private static function reading(string $page): bool
    {
        $group = $page::getNavigationGroup();

        return $group === null || in_array($group, self::READING_SCREENS, true);
    }
}
