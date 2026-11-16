<?php

use App\Modules\Demo\Models\DemoSite;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * The Miss Nori demo scanned again from its catalogue to its search index (scan:full, the operator's full scan), as Aviad
 * asked on 16.11 after the day's search fixes: its products and pages read again, its tags and its index built again,
 * its search checked. A picture already read with the same model and language is not read again (its reading would be
 * the same: never ask twice); one not read yet is. Fifteen minutes after the deploy, on the new workers. A failure
 * leaves the demo as it is.
 */
return new class extends Migration
{
    /** Minutes after the deploy: its old workers have finished the job in hand and stopped */
    private const AFTER = 15;

    /** The demo's address token (agents.lets.co.il/demo/{token}) */
    private const TOKEN = 'rwn0DR6pf8LCAaZr3cwCjoKfZQPKab4kgPgejuUn';

    public function up(): void
    {
        try {
            $shopId = DemoSite::query()->where('token', self::TOKEN)->where('status', DemoSite::READY)->value('shop_id');
            if ($shopId !== null) {
                Artisan::queue('scan:full', ['shop' => (string) $shopId])->onQueue('long')->delay(now()->addMinutes(self::AFTER));
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function down(): void
    {
        // a scan done is done
    }
};
