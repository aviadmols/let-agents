<?php

namespace App\Modules\Search\Console;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Retrieval\Models\RetrievalImage;
use App\Modules\Runs\Enums\RunStatus;
use App\Modules\Runs\Models\Run;
use App\Modules\Search\Jobs\ScanPicturesJob;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Console\Command;

/**
 * Every few minutes: a shop whose pictures are not all scanned, and whose scan is not running,
 * gets its scan back. A deploy, a crash or a part that never came cuts a scan off; this is what
 * finishes it, however many pictures the shop has. A run still marked running long after any
 * part could take is marked cut off.
 */
final class ResumePictureScansCommand extends Command
{
    protected $signature = 'search:resume-pictures';

    protected $description = 'Continue picture scans that stopped before every picture was read';

    /** Longer than any part may run. */
    private const STALE_MINUTES = 30;

    public function handle(TenantContext $tenant): int
    {
        $tenant->runUnscoped(function (): void {
            Run::query()->where('agent', 'retrieval.image_indexer')->where('status', RunStatus::Running)
                ->where('started_at', '<', now()->subMinutes(self::STALE_MINUTES))
                ->update(['status' => RunStatus::Failed, 'summary_key' => 'search::runs.scan_cut_off', 'finished_at' => now()]);
        });

        foreach (Shop::query()->where('status', 'active')->get() as $shop) {
            if (! Features::enabled('retrieval.image_index', $shop->id)) {
                continue;
            }

            $left = $tenant->run($shop->id, function (): int {
                $pictures = CatalogProduct::query()->active()->whereNotNull('image_url')->count();
                $done = RetrievalImage::query()->where(fn ($q) => $q->whereNotNull('error')->orWhereNotNull('embedded_at'))->count();

                return max(0, $pictures - $done);
            });

            $running = Run::query()->where('shop_id', $shop->id)->where('agent', 'retrieval.image_indexer')
                ->where('status', RunStatus::Running)->where('started_at', '>=', now()->subMinutes(self::STALE_MINUTES))->exists();

            if ($left > 0 && ! $running) {
                ScanPicturesJob::dispatch((string) $shop->id);
                $this->line($shop->slug.': '.$left.' pictures left, scan queued');
            }
        }

        return self::SUCCESS;
    }
}
