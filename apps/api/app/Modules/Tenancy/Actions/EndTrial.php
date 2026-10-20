<?php

namespace App\Modules\Tenancy\Actions;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Modules\Tenancy\Models\Shop;

/**
 * Ends a shop's trial, as when it starts to pay: the settings the trial limited go back to what the
 * shop had before it (StartTrial kept them). The next catalog sync reads the products beyond the
 * trial's cap, and the nightly picture scan reads their pictures.
 */
final class EndTrial
{
    public function handle(Shop $shop): Shop
    {
        if (! $shop->onTrial()) {
            return $shop;
        }

        foreach ((array) $shop->trial_saved as $key => [$kind, $was]) {
            if ($kind === 'setting') {
                $was === null ? Settings::clear($key, $shop->id) : Settings::set($key, $was, $shop->id);
            } else {
                $was === null ? Features::clearOverride($key, $shop->id) : Features::override($key, (bool) $was, $shop->id);
            }
        }

        $shop->forceFill(['trial_ends_at' => null, 'trial_saved' => null])->save();

        return $shop;
    }
}
