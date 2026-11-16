<?php

use App\Modules\Demo\Models\DemoSite;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * Every ready demo reads its tone of voice now (ReadShopVoice, the scan's new step: as Aviad asked, 16.11), a few minutes
 * after the deploy so the new workers read it: a store's own scan reads it from now on; a demo scanned before it did not.
 * Our demos only: a store's assistant keeps its tone until its next scan. One small call a demo; a failure leaves it as it is.
 */
return new class extends Migration
{
    /** Minutes after the deploy: its old workers have finished the job in hand and stopped */
    private const AFTER = 8;

    public function up(): void
    {
        try {
            DemoSite::query()->whereNotNull('shop_id')->where('status', DemoSite::READY)->pluck('shop_id')->unique()
                ->each(fn ($shopId) => Artisan::queue('assistant:voice', ['shop' => (string) $shopId])->onQueue('long')->delay(now()->addMinutes(self::AFTER)));
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function down(): void
    {
        // a tone read stays; the store changes it on its questions screen
    }
};
