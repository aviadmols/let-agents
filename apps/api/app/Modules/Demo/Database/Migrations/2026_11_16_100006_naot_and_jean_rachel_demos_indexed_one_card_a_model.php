<?php

use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Events\CatalogProductsChanged;
use App\Modules\Demo\Models\DemoSite;
use Illuminate\Database\Migrations\Migration;

/**
 * The Naot and Jean Rachel demos' search index built again, so what changed in how it is built reaches them now: one
 * model's colours as one family (clickable swatches, one card for two of one name), and an app's tags and a menu's
 * headings ("shsdchildproduct", "SHOP BY OCCASION") no filter. An empty "products changed": nothing is read again,
 * nothing is tagged, no model is asked. Found by their links' hash; a failure leaves them as they are.
 */
return new class extends Migration
{
    /** sha256 of the two demos' links */
    private const DEMOS = [
        'cd4663d90a01666ee03ac5614de5954a24970efbc3097e09edefe7ffaeb1408f',
        '153f1b378c5591a39a65c8f25059110de194d0aa287669bc4d68b4fcab01bfda',
    ];

    public function up(): void
    {
        try {
            $shops = DemoSite::query()->whereNotNull('shop_id')->whereIn('status', [DemoSite::READY, DemoSite::SCANNING])->get()
                ->filter(fn (DemoSite $site): bool => in_array(hash('sha256', (string) $site->token), self::DEMOS, true))
                ->pluck('shop_id')->unique()->values();

            app(TenantContext::class)->runUnscoped(function () use ($shops): void {
                foreach ($shops as $shopId) {
                    event(new CatalogProductsChanged((string) $shopId, []));
                }
            });
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function down(): void
    {
        // an index built is built
    }
};
