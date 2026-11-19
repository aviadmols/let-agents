<?php

namespace App\Modules\SearchSubscriptions\Jobs;

use App\Modules\SearchSubscriptions\Actions\SendWeekly;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;

/**
 * One store's weekly run (SendWeekly::forShop), at the moment the scheduler found it due ($at), so a job that waited
 * in the queue still belongs to its hour. One at a time for a store and week; a retry plans nothing twice.
 */
final class SendWeeklyForShop implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $timeout = 1200;

    public int $tries = 2;

    public int $uniqueFor = 3600;

    public function __construct(public readonly string $shopId, public readonly int $at, public readonly string $period)
    {
        $this->onQueue('long');
    }

    public function uniqueId(): string
    {
        return $this->shopId.':'.$this->period;
    }

    public function handle(SendWeekly $weekly): void
    {
        $shop = Shop::query()->find($this->shopId);
        if ($shop !== null) {
            $weekly->forShop($shop, Carbon::createFromTimestamp($this->at, (string) config('app.timezone')));
        }
    }
}
