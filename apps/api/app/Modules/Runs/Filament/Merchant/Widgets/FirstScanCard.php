<?php

namespace App\Modules\Runs\Filament\Merchant\Widgets;

use App\Modules\Admin\Contracts\OverviewBlock;
use App\Modules\Admin\Models\User;
use App\Modules\Runs\Support\FirstScan;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

/**
 * On the shop's overview: the store's scan and what it learned. A new shop scans everything; after
 * that its manager scans the changes and the new products, and only an operator scans it all again.
 * A click on a step opens what it learned: product types, attributes and tags by type, what the
 * search holds, new products by day.
 */
final class FirstScanCard extends Widget implements OverviewBlock
{
    protected string $view = 'runs::merchant.first-scan';

    protected int|string|array $columnSpan = 'full';

    /** On the page as it opens: it is the first thing to do there. */
    protected static bool $isLazy = false;

    /** The step whose details are open. */
    public ?string $open = null;

    /**
     * On the home only for a new store, until its first scan is behind it; after that what each
     * scan did is on the timeline, and "scan changes" is on the scan summary.
     */
    public static function canView(): bool
    {
        $shop = Filament::getTenant();

        // An operator keeps it: the whole chain again is theirs.
        return $shop instanceof Shop && (FirstScan::isNew($shop) || (Auth::user() instanceof User && Auth::user()->is_operator));
    }

    public function toggle(string $step): void
    {
        $this->open = $this->open === $step || ! array_key_exists($step, FirstScan::STEPS) ? null : $step;
    }

    public function start(): void
    {
        $this->begin(full: false);
    }

    /** The whole chain again: the operator's, never the shop manager's. */
    public function startFull(): void
    {
        if ($this->isOperator()) {
            $this->begin(full: true);
        }
    }

    public function isOperator(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->is_operator;
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $shop = Filament::getTenant();

        if (! $shop instanceof Shop) {
            return ['shop' => null];
        }

        $state = FirstScan::state($shop);
        $isNew = FirstScan::isNew($shop);

        return [
            'shop' => $shop,
            'state' => $state,
            'isNew' => $isNew,
            'canStart' => FirstScan::canStart($shop),
            'steps' => $state !== null ? FirstScan::steps($shop) : ($isNew ? array_keys(FirstScan::STEPS) : FirstScan::CHANGES),
            'notes' => FirstScan::notes($shop),
            'details' => $this->open === null ? [] : (FirstScan::details($shop)[$this->open] ?? []),
            'operator' => $this->isOperator(),
        ];
    }

    private function begin(bool $full): void
    {
        $shop = Filament::getTenant();

        if (! $shop instanceof Shop) {
            return;
        }

        if (FirstScan::start($shop, $full)) {
            Notification::make()->success()->title(__('runs::first_scan.started'))->body(__('runs::first_scan.started_body'))->send();
        } else {
            Notification::make()->warning()->title(__('runs::first_scan.too_soon', ['hours' => FirstScan::AGAIN_AFTER_HOURS]))->send();
        }
    }
}
