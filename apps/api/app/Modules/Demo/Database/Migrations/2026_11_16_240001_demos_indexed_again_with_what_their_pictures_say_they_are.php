<?php

use App\Modules\Demo\Models\DemoSite;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * Every ready demo's search index built again, a few minutes after the deploy so the new workers build it: what each
 * product's picture says it is is a kind it is filed under ("שורטס" are trousers: Miss Nori, 16.11, "מכנסיים אדומים" left out
 * the red shorts). A store's own index is built so every night; a demo's is not. Nothing is read again, no model is asked.
 * A failure leaves them as they are.
 */
return new class extends Migration
{
    /** Minutes after the deploy: its old workers have finished the job in hand and stopped */
    private const AFTER = 8;

    public function up(): void
    {
        try {
            DemoSite::query()->whereNotNull('shop_id')->where('status', DemoSite::READY)->pluck('shop_id')->unique()
                ->each(fn ($shopId) => Artisan::queue('search', ['step' => 'index', 'target' => (string) $shopId])->onQueue('long')->delay(now()->addMinutes(self::AFTER)));
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function down(): void
    {
        // an index built is built
    }
};
