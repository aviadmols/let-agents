<?php

namespace App\Modules\Runs\Filament\Merchant\Widgets;

use App\Modules\Admin\Contracts\OverviewBlock;
use App\Modules\Runs\Support\FirstScan;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;

/**
 * On the shop's overview: the button that scans and learns the store now, and where that scan is.
 * Large on a new shop, which the night has not scanned yet; a quiet row once it has.
 */
final class FirstScanCard extends Widget implements OverviewBlock
{
    protected string $view = 'runs::merchant.first-scan';

    protected int|string|array $columnSpan = 'full';

    /** On the page as it opens: it is the first thing to do there. */
    protected static bool $isLazy = false;

    public function start(): void
    {
        $shop = Filament::getTenant();

        if (! $shop instanceof Shop) {
            return;
        }

        if (FirstScan::start($shop)) {
            Notification::make()->success()->title(__('runs::first_scan.started'))->body(__('runs::first_scan.started_body'))->send();
        } else {
            Notification::make()->warning()->title(__('runs::first_scan.too_soon', ['hours' => FirstScan::AGAIN_AFTER_HOURS]))->send();
        }
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $shop = Filament::getTenant();

        if (! $shop instanceof Shop) {
            return ['shop' => null];
        }

        return [
            'shop' => $shop,
            'state' => FirstScan::state($shop),
            'isNew' => FirstScan::isNew($shop),
            'canStart' => FirstScan::canStart($shop),
            'steps' => array_keys(FirstScan::STEPS),
        ];
    }
}
