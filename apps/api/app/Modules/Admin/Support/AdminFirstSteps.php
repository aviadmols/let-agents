<?php

namespace App\Modules\Admin\Support;

use App\Modules\Admin\Contracts\FirstStep;
use App\Modules\Admin\Contracts\FirstSteps;
use App\Modules\Admin\Models\User;
use App\Modules\Tenancy\Models\Shop;

/**
 * The first step this module owns: invite the team. Done once a second person (not an operator, not
 * the store's own account that a platform signs in) can open the shop's panel.
 */
final class AdminFirstSteps implements FirstSteps
{
    public function steps(Shop $shop): array
    {
        $people = User::query()->where('is_operator', false)
            ->whereHas('shops', fn ($q) => $q->whereKey($shop->id))
            ->where('email', 'not like', 'store@%.wordpress')
            ->where('email', 'not like', 'owner@%.myshopify.com')
            ->count();

        return [new FirstStep(
            key: 'team',
            order: 50,
            title: __('admin::home.step.team.title'),
            body: __('admin::home.step.team.body'),
            button: __('admin::home.step.team.button'),
            url: null,
            done: $people >= 2,
        )];
    }
}
