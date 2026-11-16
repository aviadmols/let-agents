<?php

use App\Modules\Demo\Models\DemoSite;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * Every ready demo's search index built again with the pieces a picture names counted only where the title names one of
 * them (a set; never a pendant photographed on its chain). Fifteen minutes after the deploy: the run before was taken by a
 * worker still on the code before it. Stores at night. Nothing is read again, no model is asked.
 */
return new class extends Migration
{
    /** Minutes after the deploy: its old workers have finished the job in hand and stopped */
    private const AFTER = 15;

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
