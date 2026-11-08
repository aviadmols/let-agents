<?php

namespace App\Modules\Admin\Support;

use App\Core\Localization\Money;
use App\Modules\Admin\Contracts\TodayFix;
use App\Modules\Admin\Filament\Merchant\Pages\ShopOverview;
use App\Modules\Tenancy\Models\Shop;

/**
 * The week on one page, for the Monday email to the shop owner (the redesign's weekly summary, approved 11.2026, first
 * sketch): the money through search against the week before, the adds and orders, how many found what they searched,
 * the words searched most, what sold, and what waits for them, with one link back to Today. Read from the same places
 * Today reads (Home, Today); code only, nothing new is counted.
 */
final class WeeklySummary
{
    public function __construct(private readonly Home $home, private readonly Today $today) {}

    /** @return array<string, mixed> */
    public function of(Shop $shop): array
    {
        $until = now()->startOfDay();
        $since = $until->copy()->subDays(7);
        $numbers = $this->home->numbers($shop, 7, $since, $until);
        $found = $this->today->found($shop, $since, $until);
        $asked = $found === null ? 0 : $found['searches'] + $found['questions'];
        $currency = (string) ($numbers['currency'] ?? ($shop->currency ?: 'ILS'));

        return [
            'shop' => $shop->name,
            'week' => $since->format('j.n').'–'.$until->copy()->subDay()->format('j.n'),
            'money' => Money::total((float) ($numbers['revenue']['now'] ?? 0), $currency),
            'revenue' => ShopOverview::change((float) ($numbers['revenue']['now'] ?? 0), (float) ($numbers['revenue']['before'] ?? 0)),
            'adds' => (int) ($numbers['adds']['now'] ?? 0),
            'orders' => (int) ($numbers['orders']['now'] ?? 0),
            'asked' => $asked,
            'found_pct' => $asked === 0 ? null : (int) round(100 * $found['found'] / $asked),
            'top' => $this->today->top($shop, $since, $until, 5),
            'sold' => array_slice($this->today->sold($shop, $since, $until, 3), 0, 3),
            'fixes' => array_map(fn (TodayFix $fix): array => ['chip' => $fix->chip, 'title' => $fix->title, 'text' => $fix->text], array_slice($this->today->fixes($shop), 0, 3)),
            'waiting' => count($this->today->fixes($shop)),
            'url' => ShopOverview::getUrl(panel: 'merchant', tenant: $shop),
        ];
    }
}
