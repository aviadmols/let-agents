<?php

use App\Core\Facades\Settings;
use App\Modules\Demo\Models\DemoSite;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * The Profimex demo reads its site again, now that an API shut at its address is read at WordPress's other one
 * (PublicSite::get): it read its 300 projects from the store API and none of its posts and pages, whose address the
 * site's security shuts. Its catalogue is read once more, queued, so the deploy waits for no site; what it reads is
 * indexed and learned by itself after (CatalogUpdated). Never a whole scan: the demo keeps its store and its link.
 * Found by its link's hash; a failure leaves it as it is.
 */
return new class extends Migration
{
    /** sha256 of the demo's link */
    private const DEMOS = ['68cff305aa33e853035f35f338566468e5b06d229a1f9f581a357ecf9bd66fc2'];

    /** Its pages and posts read, at most */
    private const CONTENT = 300;

    public function up(): void
    {
        try {
            DemoSite::query()->whereNotNull('shop_id')->whereIn('status', [DemoSite::READY, DemoSite::SCANNING])->get()
                ->filter(fn (DemoSite $site): bool => in_array(hash('sha256', (string) $site->token), self::DEMOS, true))
                ->pluck('shop_id')->unique()
                ->each(function ($shopId): void {
                    // its 129 pages come first, and a demo's 100 would leave none of its posts: room for both
                    Settings::set('catalog.max_content_items', self::CONTENT, (string) $shopId);
                    Artisan::queue('catalog:sync', ['shop' => (string) $shopId])->onQueue('long');
                });
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function down(): void
    {
        // what was read stays; the next reading replaces it
    }
};
