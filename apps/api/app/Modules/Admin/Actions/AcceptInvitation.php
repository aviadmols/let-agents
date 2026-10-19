<?php

namespace App\Modules\Admin\Actions;

use App\Modules\Admin\Models\ShopInvitation;
use App\Modules\Admin\Models\User;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Support\Facades\DB;

/**
 * The invited person opened their link: they become one of the shop's people with the role they were
 * invited as. A new person is created with the password they chose; someone who already has an account
 * keeps it (and their password), and a role they already had in the shop stays.
 */
final class AcceptInvitation
{
    public function handle(ShopInvitation $invitation, ?string $password = null, ?string $name = null): User
    {
        return DB::transaction(function () use ($invitation, $password, $name): User {
            $user = User::query()->whereRaw('lower(email) = ?', [mb_strtolower($invitation->email)])->first();

            if ($user === null) {
                $user = User::query()->create([
                    'name' => trim((string) ($name ?: $invitation->name ?: strstr($invitation->email, '@', true))),
                    'email' => $invitation->email,
                    'password' => (string) $password,
                    'locale' => $invitation->locale,
                ]);
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            /** @var Shop $shop */
            $shop = Shop::query()->findOrFail($invitation->shop_id);

            if ($user->roleIn($shop) === null) {
                $user->attachShop($shop, $invitation->role);
            }

            $invitation->forceFill(['accepted_at' => now(), 'user_id' => $user->id])->save();

            return $user;
        });
    }
}
