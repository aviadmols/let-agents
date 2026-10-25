<?php

namespace App\Modules\Widget\Filament\Merchant\Pages;

use App\Core\Facades\Settings;
use App\Core\Filament\EditsShopSettings;
use App\Modules\Widget\Support\AnsweringZone;
use App\Modules\Widget\Support\OpeningHours as Week;
use App\Modules\Widget\Support\PageCache;
use App\Modules\Widget\Support\ShopWeek;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Settings · Answering hours, as the designer's panel lays it out: a day at a time, a switch and two
 * times, an off day closed. The week is measured by the shop's clock (AnsweringZone). The product pages
 * carry the hours (WhatsApp now, details after), so their cache is forgotten on save. The shop comes
 * from the address and is never a choice here.
 */
class OpeningHours extends Page
{
    use EditsShopSettings;

    public static string $sideGroup = 'team';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'settings';

    protected static ?int $navigationSort = 90;

    protected static ?string $slug = 'settings/hours';

    protected string $view = 'widget::merchant.settings.hours';

    public static function getNavigationLabel(): string
    {
        return __('widget::shop_settings.hours.nav');
    }

    public function getTitle(): string
    {
        return __('admin::shop_settings.title');
    }

    public function getSubheading(): ?string
    {
        return __('admin::shop_settings.subheading');
    }

    protected function fields(): array
    {
        return [];
    }

    protected function loadMore(): void
    {
        $shop = $this->shopId();
        // A week the shop never set is the usual one where its clock runs: Sunday to Thursday in Israel, Monday to Friday elsewhere.
        $week = ShopWeek::for($shop);

        foreach (Week::DAYS as $day) {
            $ends = Week::split($week[$day]);
            $this->data['days'][$day] = [
                'open' => $ends['from'] !== null,
                'from' => $ends['from'] ?? '09:00',
                'until' => $ends['until'] ?? '17:00',
            ];
        }

        $this->data['timezone'] = AnsweringZone::for($shop);
    }

    protected function checkMore(): void
    {
        foreach (Week::DAYS as $day) {
            $row = (array) ($this->data['days'][$day] ?? []);
            if (($row['open'] ?? false) && Week::compose($row['from'] ?? null, $row['until'] ?? null) === '') {
                $this->problems["days.{$day}"] = __('widget::shop_settings.hours.bad_range', ['day' => __("widget::ui.hours.days.{$day}")]);
            }
        }
    }

    protected function saveMore(): void
    {
        $shop = $this->shopId();
        $zone = AnsweringZone::for($shop);

        foreach (Week::DAYS as $day) {
            $row = (array) ($this->data['days'][$day] ?? []);
            $value = ($row['open'] ?? false) ? Week::compose($row['from'] ?? null, $row['until'] ?? null) : '';

            // The shop's own word only where it differs from the usual week where its clock runs.
            Settings::clear("widget.hours_{$day}", $shop);
            if ($value !== ShopWeek::day($day, $shop, $zone)) {
                Settings::set("widget.hours_{$day}", $value, $shop);
            }
        }
    }

    protected function saved(): void
    {
        PageCache::forgetShop($this->shopId());
    }
}
