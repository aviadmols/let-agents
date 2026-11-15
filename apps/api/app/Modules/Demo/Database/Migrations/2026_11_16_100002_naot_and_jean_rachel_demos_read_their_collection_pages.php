<?php

use App\Modules\Demo\Models\DemoSite;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * The Naot and Jean Rachel demos read their collection pages for one model's versions sold as products of their own
 * (ReadCollectionSiblings), as Aviad asked: queued, so the deploy waits for no store; the search index is built again
 * by itself when groups are found. Only this step, never a whole scan: the demos keep their stores and their links.
 * Found by their links' hash; a failure leaves them as they are.
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
            DemoSite::query()->whereNotNull('shop_id')->whereIn('status', [DemoSite::READY, DemoSite::SCANNING])->get()
                ->filter(fn (DemoSite $site): bool => in_array(hash('sha256', (string) $site->token), self::DEMOS, true))
                ->pluck('shop_id')->unique()
                ->each(fn ($shopId) => Artisan::queue('catalog:collection-siblings', ['shop' => (string) $shopId])->onQueue('long'));
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function down(): void
    {
        // what was read stays; the next reading replaces it
    }
};
