<?php

use App\Core\Facades\Settings;
use App\Modules\Demo\Models\DemoSite;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * The Naot and Jean Rachel demos (our own stores, Naot and Jean Rachel QA, 16.11): the tags they keep for their shop
 * pages are no filter (search.hidden_tags), and their search index is built again, a few minutes after the deploy so the
 * new workers build it: those tags out, and a sale's price before it struck through ("Solanum": $209.95, now $99). No
 * model is asked; nothing is read again. Found by their links' hash; a failure leaves them as they are.
 */
return new class extends Migration
{
    /** sha256 of each demo's link => the tags it keeps for its shop pages */
    private const HIDDEN = [
        // Naot
        'cd4663d90a01666ee03ac5614de5954a24970efbc3097e09edefe7ffaeb1408f' => 'Shop, Gift Guide, Vacation Shop, Everyday Essentials Shop, Naot Hero Styles, SHOP BY OCCASION',
        // Jean Rachel
        '153f1b378c5591a39a65c8f25059110de194d0aa287669bc4d68b4fcab01bfda' => 'Best Sellers',
    ];

    /** Minutes after the deploy: its old workers have finished the job in hand and stopped */
    private const AFTER = 8;

    public function up(): void
    {
        try {
            DemoSite::query()->whereNotNull('shop_id')->whereIn('status', [DemoSite::READY, DemoSite::SCANNING])->get()
                ->each(function (DemoSite $site): void {
                    $hidden = self::HIDDEN[hash('sha256', (string) $site->token)] ?? null;
                    if ($hidden === null) {
                        return;
                    }
                    Settings::set('search.hidden_tags', $hidden, (string) $site->shop_id);
                    Artisan::queue('search', ['step' => 'index', 'target' => (string) $site->shop_id])->onQueue('long')->delay(now()->addMinutes(self::AFTER));
                });
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function down(): void
    {
        // what was set stays; the team changes it on the settings screen
    }
};
