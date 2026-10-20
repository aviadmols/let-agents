<?php

namespace App\Modules\Admin\Filament\Merchant\Widgets;

use App\Core\Facades\Settings;
use App\Modules\Admin\Contracts\DailySummaries;
use App\Modules\Admin\Contracts\DailySummary;
use App\Modules\Admin\Contracts\OverviewBlock;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Throwable;

/**
 * The daily reports on the shop's home: each kind (the questions shoppers asked, the site's daily
 * check) a slider of days, the latest first, each day a card of what it did. From every module that
 * tags DailySummaries.
 */
final class DailyReportsCard extends Widget implements OverviewBlock
{
    protected string $view = 'admin::merchant.daily-reports';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 20;

    /** With the page: a few rows a day, read at once. */
    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        return Filament::getTenant() instanceof Shop;
    }

    /** @return array{kinds: list<array{label: string, days: list<DailySummary>}>} */
    protected function getViewData(): array
    {
        $shop = Filament::getTenant();
        $kinds = [];

        if ($shop instanceof Shop) {
            $since = now()->subDays((int) Settings::get('admin.daily_reports_days'));
            foreach (app()->tagged(DailySummaries::TAG) as $source) {
                try {
                    $days = $source->summaries((string) $shop->id, $since);
                } catch (Throwable $e) {
                    // One module's trouble never takes the home down.
                    report($e);

                    continue;
                }
                if ($days !== []) {
                    $kinds[] = ['label' => $source->label(), 'days' => $days];
                }
            }
        }

        return ['kinds' => $kinds];
    }
}
