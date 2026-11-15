<?php

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Events\CatalogProductsChanged;
use App\Modules\Demo\Models\DemoSite;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Database\Migrations\Migration;

/**
 * Two demos Aviad made on 15.11, of an American and an Australian store, built in Hebrew and in shekels: in English and in
 * dollars, as he asked. Found by their links' hash (the links themselves stay out of the code); every request shown the
 * same demo store too. Their search speaks English, their answers and index are English, and their prices show the
 * store's dollars. Nothing else is touched.
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
        $shops = DemoSite::query()->whereNotNull('shop_id')->get()
            ->filter(fn (DemoSite $site): bool => in_array(hash('sha256', (string) $site->token), self::DEMOS, true))
            ->pluck('shop_id')->unique()->values();

        app(TenantContext::class)->runUnscoped(function () use ($shops): void {
            foreach ($shops as $shopId) {
                $shop = Shop::query()->find($shopId);
                if ($shop === null) {
                    continue;
                }

                DemoSite::query()->where('shop_id', $shopId)->get()
                    ->each(fn (DemoSite $site) => $site->forceFill(['choices' => ['language' => 'en'] + (array) $site->choices])->save());
                $shop->forceFill(['content_locale' => 'en', 'currency' => 'USD'])->save();
                Settings::set('search.widget_language', 'en', (string) $shopId);
                Settings::set('search.store_currency', true, (string) $shopId);
                // the index again, in English (an empty "products changed": nothing is read again, nothing is tagged)
                event(new CatalogProductsChanged((string) $shopId, []));
            }
        });
    }

    public function down(): void
    {
        // a demo's language and currency stay as they were set; the demo itself ends with its days
    }
};
