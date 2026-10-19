<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Actions\AcceptInvitation;
use App\Modules\Admin\Filament\Merchant\Pages\ShopOverview;
use App\Modules\Admin\Http\Middleware\ApplyAdminLocale;
use App\Modules\Admin\Models\ShopInvitation;
use App\Modules\Admin\Models\User;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;

/**
 * The link in an invitation email (signed, and carrying the invitation's own token): a new person sets
 * a password and comes straight into the shop's panel; someone who already has an account is added to the
 * shop and signs in as always. A link that was used, sent again or ran out says so.
 */
final class InvitationController
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function show(Request $request, string $invitation, string $token): View|RedirectResponse
    {
        $found = $this->find($request, $invitation, $token);

        if (! $found instanceof ShopInvitation) {
            return $this->page('closed', null, ['reason' => $found]);
        }

        $this->speak($found);

        // An account with this email exists: it joins the shop now and signs in with its own password.
        if (User::query()->whereRaw('lower(email) = ?', [mb_strtolower($found->email)])->exists()) {
            $user = app(AcceptInvitation::class)->handle($found);

            return $this->enter($request, $user, $found, Auth::id() === $user->id);
        }

        return $this->page('accept', $found);
    }

    public function accept(Request $request, string $invitation, string $token): View|RedirectResponse
    {
        $found = $this->find($request, $invitation, $token);

        if (! $found instanceof ShopInvitation) {
            return $this->page('closed', null, ['reason' => $found]);
        }

        $this->speak($found);

        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'password' => ['required', 'string', 'min:'.User::MIN_PASSWORD_LENGTH, 'max:200', 'confirmed'],
        ], [
            'name.*' => __('admin::invitations.page.name_short'),
            'password.min' => __('admin::invitations.page.password_short', ['min' => User::MIN_PASSWORD_LENGTH]),
            'password.confirmed' => __('admin::invitations.page.password_mismatch'),
            'password.*' => __('admin::invitations.page.password_short', ['min' => User::MIN_PASSWORD_LENGTH]),
        ]);

        $user = app(AcceptInvitation::class)->handle($found, $data['password'], $data['name']);

        return $this->enter($request, $user, $found, true);
    }

    /** The invitation the link names, or why it cannot be used: gone, used, expired. */
    private function find(Request $request, string $id, string $token): ShopInvitation|string
    {
        if (! URL::hasCorrectSignature($request)) {
            return 'gone';
        }

        if (! URL::signatureHasNotExpired($request)) {
            return 'expired';
        }

        $invitation = $this->tenant->runUnscoped(fn (): ?ShopInvitation => ShopInvitation::query()->find($id));

        if ($invitation === null || ! $invitation->matches($token)) {
            return 'gone';
        }

        if ($invitation->accepted_at !== null) {
            return 'used';
        }

        return $invitation->expired() ? 'expired' : $invitation;
    }

    /** Into the shop's panel when signed in, or to the sign-in with a word on what happened. */
    private function enter(Request $request, User $user, ShopInvitation $invitation, bool $signIn): View|RedirectResponse
    {
        /** @var Shop $shop */
        $shop = Shop::query()->findOrFail($invitation->shop_id);

        if (! $signIn) {
            return $this->page('joined', $invitation);
        }

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put(ApplyAdminLocale::SESSION_KEY, $invitation->locale);

        return redirect()->to(ShopOverview::getUrl(panel: User::MERCHANT_PANEL, tenant: $shop));
    }

    private function speak(ShopInvitation $invitation): void
    {
        app()->setLocale($invitation->locale);
        Carbon::setLocale($invitation->locale);
    }

    /** @param  array<string, mixed>  $with */
    private function page(string $state, ?ShopInvitation $invitation, array $with = []): View
    {
        $shop = $invitation === null ? null : Shop::query()->find($invitation->shop_id);

        return view('admin::invitation.page', [
            'state' => $state,
            'invitation' => $invitation,
            'shop' => $shop,
            'login' => Filament::getPanel(User::MERCHANT_PANEL)->getLoginUrl(),
            ...$with,
        ]);
    }
}
