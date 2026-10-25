<?php

namespace App\Modules\Demo\Jobs;

use App\Modules\Demo\Models\DemoSite;
use App\Modules\Demo\Support\Screenshots;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * The picture of a demo's home page, for its background (Screenshots), taken while the store's scan runs. Done or
 * given up on, shot_at says so: a demo without a picture still shows the search, and says why.
 */
final class ShootDemoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 300;

    public int $tries = 2;

    public int $backoff = 30;

    public function __construct(public readonly string $demoId) {}

    public function handle(): void
    {
        $site = DemoSite::query()->find($this->demoId);

        if ($site === null || $site->shot_at !== null || ! $site->live()) {
            return;
        }

        // a phone's picture only: the demo is shown in a phone, on a computer too
        $site->forceFill(['screenshot' => null, 'screenshot_mobile' => Screenshots::take($site->url), 'shot_at' => now()])->save();
    }

    public function failed(?Throwable $e): void
    {
        DemoSite::query()->whereKey($this->demoId)->whereNull('shot_at')->update(['shot_at' => now()]);
    }
}
