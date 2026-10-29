<?php

namespace App\Modules\Admin\Actions;

use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Admin\Models\DesignPass;
use App\Modules\Admin\Models\User;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Support\Str;

/**
 * A designer's pass into one shop's panel, for a day: the shop's design account (one per shop, a team member: it
 * changes what the team may change, never the people, the plan or the connection), and a link with a token of its
 * own. The link is returned once; only the token's hash is kept. Code only.
 */
final class CreateDesignPass
{
    public const HOURS = 24;

    /** @return array{pass: DesignPass, token: string} */
    public function handle(Shop $shop, ?int $createdBy, int $hours = self::HOURS): array
    {
        $email = 'design+'.Str::lower((string) $shop->slug).'@design.lets.co.il';
        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first()
            ?? User::query()->create(['name' => __('admin::design.account', ['shop' => $shop->name]), 'email' => $email, 'password' => Str::password(40), 'is_operator' => false]);
        $user->attachShop($shop, ShopRole::Member);

        $token = Str::random(48);
        $pass = DesignPass::query()->create([
            'shop_id' => $shop->id,
            'user_id' => $user->id,
            'token_hash' => DesignPass::hash($token),
            'expires_at' => now()->addHours(max(1, min($hours, self::HOURS))),
            'created_by' => $createdBy,
        ]);

        return ['pass' => $pass, 'token' => $token];
    }
}
