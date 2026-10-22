<?php

namespace App\Modules\Admin\Actions;

use App\Modules\Tenancy\Models\Shop;

/** The manager went through the first-run screens: from now on the shop's panel opens on its home. Once. */
final class FinishOnboarding
{
    public function handle(Shop $shop): void
    {
        if ($shop->onboarded_at !== null) {
            return;
        }

        $shop->forceFill(['onboarded_at' => now()])->save();
    }
}
