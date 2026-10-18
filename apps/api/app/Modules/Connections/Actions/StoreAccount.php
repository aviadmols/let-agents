<?php

namespace App\Modules\Connections\Actions;

use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Admin\Models\User;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Support\Str;

/**
 * The account WordPress signs in when the plugin opens the panel inside wp-admin: one per shop,
 * that shop only (store@{site_key}.wordpress), an owner of it. The plugin's token vouches for
 * the store, never for a person: it never signs in an account found by email, which might own
 * another shop or be an operator's.
 */
final class StoreAccount
{
    public function for(Shop $shop, string $siteKey): User
    {
        $user = User::query()->firstOrCreate(['email' => 'store@'.$siteKey.'.wordpress'], [
            'name' => $shop->name,
            'password' => Str::random(40),
        ]);

        if ($user->is_operator) {
            // Never: the address is ours alone, but an operator is never signed in by a store.
            abort(403);
        }

        if (! $user->shops()->whereKey($shop->id)->exists()) {
            $user->attachShop($shop, ShopRole::Owner);
        }

        return $user;
    }
}
