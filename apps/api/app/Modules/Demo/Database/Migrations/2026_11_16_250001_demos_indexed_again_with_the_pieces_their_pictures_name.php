<?php

use App\Modules\Demo\Models\DemoSite;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * Every ready demo's search index built again, a few minutes after the deploy so the new workers build it: the pieces a
 * product's picture names are kinds it is filed under (a set of a sweatshirt and wide trousers is trousers too: Miss Nori,
 * 16.11), and a value read from the words holds what its own title says. Stores at night. Nothing is read again, no model
 * is asked. A failure leaves them as they are.
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
