<?php

namespace App\Modules\Tenancy\Actions;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Modules\Tenancy\Models\Shop;
use App\Modules\Tenancy\Support\TrialLimits;

/**
 * Puts a shop on trial for tenancy.trial_days (or the days given): its settings take the trial's
 * limits (TrialLimits), and what the shop had before is kept, so EndTrial puts it back. A shop
 * already on trial only gets its days renewed: what it had before the first trial stays kept.
 */
final class StartTrial
{
    public function handle(Shop $shop, ?int $days = null): Shop
    {
        $saved = $shop->onTrial() ? (array) $shop->trial_saved : [];

        foreach (TrialLimits::all() as $key => [$kind, $value]) {
            if (! $shop->onTrial()) {
                $saved[$key] = [$kind, $kind === 'setting' ? Settings::overrideFor($key, $shop->id) : Features::overrideFor($key, $shop->id)];
            }

            $kind === 'setting' ? Settings::set($key, $value, $shop->id) : Features::override($key, (bool) $value, $shop->id);
        }

        $shop->forceFill([
            'trial_ends_at' => now()->addDays(max(1, $days ?? (int) Settings::get('tenancy.trial_days'))),
            'trial_saved' => $saved,
        ])->save();

        return $shop;
    }
}
