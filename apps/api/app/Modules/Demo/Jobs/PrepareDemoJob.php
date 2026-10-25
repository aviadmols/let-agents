<?php

namespace App\Modules\Demo\Jobs;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Contracts\ShopScan;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Demo\Models\DemoSite;
use App\Modules\Demo\Support\PublicSite;
use App\Modules\Tenancy\Enums\ShopPlatform;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * A verified demo, made ready to scan, a step at a time, each kept on its row (read_at, scan_at) for the scan screen
 * to show: what the store runs on, its name, language and currency, read from its site; then the store's first scan
 * (ShopScan), the same a real store gets, held to the demo's limits (BuildDemoStore). The home page's picture comes
 * after, in a job of its own (ShootDemoJob), so a slow picture never holds the scan. A step done is not done again:
 * a job cut off is tried again (tries, and SweepDemos for one that never came back). A site that does not answer
 * fails the demo, said plainly on the scan screen.
 */
final class PrepareDemoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 180;

    public int $tries = 3;

    public int $backoff = 20;

    public function __construct(public readonly string $demoId) {}

    public function handle(TenantContext $tenant): void
    {
        $site = DemoSite::query()->find($this->demoId);
        $shop = $site?->shop;

        if ($site === null || $shop === null || $site->status !== DemoSite::SCANNING) {
            return;
        }

        $site->increment('attempts');

        if ($site->read_at === null) {
            Log::info('demo: reading the site', ['demo' => $site->id, 'host' => $site->host]);
            $read = PublicSite::read($site->host);

            if ($read === null && $this->attempts() < $this->tries) {
                $this->release($this->backoff);

                return;
            }
            if ($read === null) {
                $site->forceFill(['status' => DemoSite::FAILED, 'error' => 'unreachable'])->save();

                return;
            }

            $tenant->runUnscoped(function () use ($shop, $read): void {
                $connection = StoreConnection::query()->where('shop_id', $shop->id)->first();
                $connection?->forceFill(['site_info' => ['demo' => ['platform' => $read['platform'], 'currency' => $read['currency']]] + (array) $connection->site_info])->save();
            });
            $language = $site->choices['language'] ?? null;
            $shop->forceFill(array_filter([
                'platform' => $read['platform'] === PublicSite::SHOPIFY ? ShopPlatform::Shopify : ShopPlatform::WooCommerce,
                'name' => $read['title'],
                'currency' => $read['currency'],
                // the language the store's content is in: as whoever asked chose it, else as its own site says
                'content_locale' => $language ?? (in_array($read['locale'], ['he', 'en'], true) ? $read['locale'] : null),
            ]))->save();
            $site->forceFill(['platform' => $read['platform'], 'title' => $read['title'], 'store_products' => $read['products'], 'read_at' => now()])->save();
        }

        if ($site->scan_at === null) {
            Log::info('demo: starting the scan', ['demo' => $site->id]);
            if (! app()->bound(ShopScan::class) || ! app(ShopScan::class)->start($shop->fresh())) {
                $site->forceFill(['status' => DemoSite::FAILED, 'error' => 'scan'])->save();

                return;
            }
            $site->forceFill(['scan_at' => now()])->save();
        }

        if ($site->shot_at === null) {
            ShootDemoJob::dispatch($site->id);
        }
    }
}
