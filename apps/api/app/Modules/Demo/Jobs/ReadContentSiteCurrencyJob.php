<?php

namespace App\Modules\Demo\Jobs;

use App\Modules\Demo\Support\WordPressSite;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A content site's currency, as its own pages state their prices: read in the background, since reading it means
 * reading the site (its types, its sitemap, a few of its pages), never while someone waits on "Connect" (Gil Travel,
 * 28.10: the click read the whole site and looked like it did nothing). The read is kept six hours, for the scan.
 */
final class ReadContentSiteCurrencyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 180;

    public int $tries = 2;

    public function __construct(public readonly string $shopId, public readonly string $host) {}

    public function handle(): void
    {
        $currency = WordPressSite::read($this->host)['currency'] ?? null;

        if (is_string($currency) && preg_match('/^[A-Z]{3}$/', $currency) === 1) {
            Shop::query()->whereKey($this->shopId)->update(['currency' => $currency]);
        }
    }
}
