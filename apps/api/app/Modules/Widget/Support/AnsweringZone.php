<?php

namespace App\Modules\Widget\Support;

use App\Core\Facades\Settings;
use App\Core\Settings\ValueSource;
use App\Modules\Tenancy\Models\Shop;
use DateTimeZone;

/**
 * The clock the answering hours are read by: the shop's own time zone (shops.timezone, as its platform
 * gave it), unless the team set one for these hours on purpose (widget.whatsapp_timezone, the shop's
 * own value). The setting's default is Israel's, from before shops kept their time zone, so a shop in
 * New York is never told its hours run on Jerusalem's clock. The search window reads the same rule
 * (SearchIndexController), by key.
 */
final class AnsweringZone
{
    public static function for(string $shopId): string
    {
        if ($shopId !== '' && Settings::source('widget.whatsapp_timezone', $shopId) === ValueSource::Shop) {
            return (string) Settings::get('widget.whatsapp_timezone', $shopId);
        }

        $zone = $shopId === '' ? '' : (string) Shop::query()->whereKey($shopId)->value('timezone');

        return self::valid($zone) ? $zone : (string) Settings::get('widget.whatsapp_timezone', $shopId === '' ? null : $shopId);
    }

    private static function valid(string $zone): bool
    {
        if ($zone === '') {
            return false;
        }

        try {
            new DateTimeZone($zone);

            return true;
        } catch (\Exception) {
            return false;
        }
    }
}
