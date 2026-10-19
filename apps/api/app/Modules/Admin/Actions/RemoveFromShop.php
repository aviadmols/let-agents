<?php

namespace App\Modules\Admin\Actions;

use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Admin\Models\User;
use App\Modules\Tenancy\Models\Shop;

/**
 * Takes someone out of the shop's people. Their account stays (they may belong to other shops); the
 * shop always keeps at least one owner.
 */
final class RemoveFromShop
{
    /** @return bool whether they were removed */
    public function handle(Shop $shop, User $user): bool
    {
        $role = $user->roleIn($shop);

        if ($role === null) {
            return false;
        }

        if ($role === ShopRole::Owner && $shop->belongsToMany(User::class, 'shop_user')->wherePivot('role', ShopRole::Owner->value)->count() <= 1) {
            return false;
        }

        $user->shops()->detach($shop->getKey());

        return true;
    }
}
