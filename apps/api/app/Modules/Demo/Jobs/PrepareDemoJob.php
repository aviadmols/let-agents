<?php

namespace App\Modules\Demo\Jobs;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Contracts\ShopScan;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Demo\Models\DemoSite;
use App\Modules\Demo\Support\PublicSite;
use App\Modules\Demo\Support\Screenshots;
use App\Modules\Tenancy\Enums\ShopPlatform;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * A verified demo, made ready to scan: what the store runs on, its name, language and currency, read from its site;
 * the pictures of its home page for the demo's background; then the store's first scan (ShopScan), the same a real
 * store gets, held to the demo's limits (BuildDemoStore). A site that stopped answering fails the demo, said plainly.
 */
final class PrepareDemoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public readonly string $demoId) {}

    public function handle(TenantContext $tenant): void
    {
        $site = DemoSite::query()->find($this->demoId);
        $shop = $site?->shop;

        if ($site === null || $shop === null || $site->status !== DemoSite::SCANNING) {
            return;
        }

        $read = PublicSite::read($site->host);
        if ($read === null) {
            $site->forceFill(['status' => DemoSite::FAILED, 'error' => 'unreachable'])->save();

            return;
        }

        $tenant->runUnscoped(function () use ($shop, $read): void {
            $connection = StoreConnection::query()->where('shop_id', $shop->id)->first();
            $connection?->forceFill(['site_info' => ['demo' => ['platform' => $read['platform'], 'currency' => $read['currency']]] + (array) $connection->site_info])->save();
        });
        $shop->forceFill(array_filter([
            'platform' => $read['platform'] === PublicSite::SHOPIFY ? ShopPlatform::Shopify : ShopPlatform::WooCommerce,
            'name' => $read['title'],
            'currency' => $read['currency'],
        ]))->save();
        $site->forceFill(['platform' => $read['platform'], 'title' => $read['title']])->save();

        try {
            $shots = Screenshots::take($site->url);
            $site->forceFill(['screenshot' => $shots['desktop'], 'screenshot_mobile' => $shots['mobile']])->save();
        } catch (Throwable $e) {
            report($e);
        }

        if (! app()->bound(ShopScan::class) || ! app(ShopScan::class)->start($shop->fresh())) {
            $site->forceFill(['status' => DemoSite::FAILED, 'error' => 'scan'])->save();
        }
    }
}
