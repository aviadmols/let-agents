<?php

namespace App\Modules\Widget\Support;

use App\Core\Facades\Settings;
use App\Core\Settings\ValueSource;
use App\Modules\Tenancy\Models\Shop;

/**
 * A shop's answering week as it reads it (widget.hours_{day}): a day the shop set is its own; a day it never
 * set follows the usual week where its clock runs (AnsweringZone). The declared week is Israel's, Sunday to
 * Thursday; a shop anywhere else works Monday to Friday, its Friday as long as its Monday, Sunday closed.
 * The search window reads the same rule (SearchIndexController), by key.
 */
final class ShopWeek
{
    /** @return array<string, string> day => "HH:MM-HH:MM" or empty (closed), Sunday first */
    public static function for(string $shopId, ?string $timezone = null): array
    {
        $timezone ??= AnsweringZone::for($shopId);
        $week = [];

        foreach (OpeningHours::DAYS as $day) {
            $week[$day] = self::day($day, $shopId, $timezone);
        }

        return $week;
    }

    public static function day(string $day, string $shopId, string $timezone): string
    {
        $key = "widget.hours_{$day}";

        if (Settings::source($key, $shopId) === ValueSource::Shop || Shop::israeli($timezone)) {
            return (string) Settings::get($key, $shopId);
        }

        return match ($day) {
            'saturday', 'sunday' => '',
            default => (string) Settings::get($key, $shopId) ?: (string) Settings::get('widget.hours_monday', $shopId),
        };
    }
}
