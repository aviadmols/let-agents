<?php

namespace App\Modules\Runs\Support;

use App\Modules\Admin\Contracts\HomeSource;
use App\Modules\Admin\Contracts\HomeStatus;
use App\Modules\Admin\Contracts\ShopNotice;
use App\Modules\Runs\Enums\RunStatus;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Runs\Models\Run;
use App\Modules\Tenancy\Models\Shop;
use Carbon\CarbonInterface;

/**
 * The store's scan on the shop's home: learned every night, or not scanned yet; and the night's
 * scan as a line in the notices.
 */
final class ScanHome implements HomeSource
{
    public function statuses(Shop $shop): array
    {
        $last = Run::query()->where('shop_id', $shop->id)->where('status', RunStatus::Succeeded)->latest('created_at')->value('created_at');

        return [new HomeStatus(
            key: 'scan',
            name: __('runs::home.scan.name'),
            state: FirstScan::isNew($shop) && FirstScan::state($shop) === null ? HomeStatus::SETUP : HomeStatus::ON,
            note: $last === null ? __('runs::home.scan.never') : __('runs::home.scan.last', ['when' => $last->diffForHumans()]),
            order: 5,
        )];
    }

    public function notices(Shop $shop): array
    {
        $night = Run::query()->where('shop_id', $shop->id)->where('trigger', RunTrigger::Schedule)
            ->where('created_at', '>=', now()->subDay())->get(['status', 'created_at']);

        if ($night->isEmpty()) {
            return [];
        }

        $failed = $night->where('status', RunStatus::Failed)->count();

        return [new ShopNotice(
            key: 'runs.night.'.now()->toDateString(),
            kind: ShopNotice::REPORT,
            tone: $failed > 0 ? '' : 'good',
            title: __('runs::home.night.title'),
            body: $failed > 0 ? __('runs::home.night.some', ['failed' => $failed]) : __('runs::home.night.all', ['count' => $night->count()]),
            source: __('runs::home.scan.name'),
            at: $night->max('created_at'),
            weight: 5,
        )];
    }

    public function figures(Shop $shop, CarbonInterface $since, CarbonInterface $until, CarbonInterface $before): array
    {
        return [];
    }
}
