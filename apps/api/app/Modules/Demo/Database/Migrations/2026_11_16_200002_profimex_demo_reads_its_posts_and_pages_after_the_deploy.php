<?php

use App\Modules\Demo\Models\DemoSite;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * The Profimex demo reads its site again, a few minutes after the deploy: the reading queued by the migration before
 * (…200001) is taken by a worker still running the code it replaced, while the deploy starts the new ones, and read no
 * post again. Delayed, it is read by the code that reads an API shut at its address. Its room for 300 pages and posts
 * was set then. Found by its link's hash; a failure leaves it as it is.
 */
return new class extends Migration
{
    /** sha256 of the demo's link */
    private const DEMOS = ['68cff305aa33e853035f35f338566468e5b06d229a1f9f581a357ecf9bd66fc2'];

    /** Minutes after the deploy: its old workers have finished the job in hand and stopped */
    private const AFTER = 8;

    public function up(): void
    {
        try {
            DemoSite::query()->whereNotNull('shop_id')->whereIn('status', [DemoSite::READY, DemoSite::SCANNING])->get()
                ->filter(fn (DemoSite $site): bool => in_array(hash('sha256', (string) $site->token), self::DEMOS, true))
                ->pluck('shop_id')->unique()
                ->each(fn ($shopId) => Artisan::queue('catalog:sync', ['shop' => (string) $shopId])->onQueue('long')->delay(now()->addMinutes(self::AFTER)));
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function down(): void
    {
        // what was read stays; the next reading replaces it
    }
};
