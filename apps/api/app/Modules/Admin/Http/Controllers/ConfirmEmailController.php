<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * GET account/email/{user}?email=…&signature=… (a signed link, mailed to the new address): the address becomes the
 * account's and counts as proven, since only its inbox had the link. Not an address another account holds by now, and
 * never one the platform keeps for its own accounts (User::reservedEmail).
 */
final class ConfirmEmailController
{
    public function __invoke(Request $request, User $user): RedirectResponse
    {
        $email = mb_strtolower(trim((string) $request->query('email', '')));

        abort_if(filter_var($email, FILTER_VALIDATE_EMAIL) === false || User::reservedEmail($email), 404);
        abort_if(User::query()->whereKeyNot($user->id)->whereRaw('lower(email) = ?', [$email])->exists(), 409);

        $user->forceFill(['email' => $email, 'email_verified_at' => now()])->save();

        return redirect()->to('/merchant')->with('status', __('admin::shop_settings.profile.email_confirmed'));
    }
}
