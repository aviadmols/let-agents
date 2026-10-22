<?php

namespace App\Modules\Admin\Actions;

use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Admin\Models\User;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Support\Facades\Log;

/**
 * A person deletes their own account: they leave every shop, and their name stays only where it was written down
 * (who decided what, set to nobody). The shops stay. Not while a shop would be left with no owner: they first make
 * someone else an owner, or ask for the shop to be deleted. An operator's account is not deleted here.
 */
final class DeleteOwnAccount
{
    /** @return list<string> the shops that would be left with no owner; empty when the account was deleted */
    public function handle(User $user): array
    {
        $alone = $this->alone($user);

        if ($alone !== [] || $user->is_operator) {
            return $alone;
        }

        Log::info('A person deleted their own account', ['user' => $user->id]);
        $user->delete();

        return [];
    }

    /** @return list<string> the names of the shops only this person owns, that are not being deleted */
    public function alone(User $user): array
    {
        return $user->shops()->wherePivot('role', ShopRole::Owner->value)->get()
            ->filter(fn (Shop $shop): bool => ! $shop->leaving()
                && $shop->belongsToMany(User::class, 'shop_user')->wherePivot('role', ShopRole::Owner->value)->count() <= 1)
            ->map(fn (Shop $shop): string => (string) $shop->name)->values()->all();
    }
}
