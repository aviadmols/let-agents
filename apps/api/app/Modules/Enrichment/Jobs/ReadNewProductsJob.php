<?php

namespace App\Modules\Enrichment\Jobs;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Actions\BuildCards;
use App\Modules\Enrichment\Actions\ReadProductsInCode;
use App\Modules\Enrichment\Events\FactsDecided;
use App\Modules\Enrichment\Models\EnrichmentCodeReading;
use App\Modules\Runs\Enums\RunTrigger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * The products a sync brought that code has never read, read by code at once (enrichment.read_new_at_once): what they are,
 * their metal, colour and sizes as the store files and names them, then the search index and their cards built again. A
 * product published in the morning is filtered and matched by noon, not after the next night's reading (Rocks, 19.11: 55
 * products published at noon had no kind and no metal in the search until the night after). Code only, no model, any
 * store. Only in a shop code has read before (its first scan reads the whole catalogue), and at most AT_MOST at a time:
 * more are the night's.
 */
final class ReadNewProductsJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Seconds the job waits for the rest of a sync's products. */
    public const GATHER_SECONDS = 60;

    /** The most new products read at once. */
    public const AT_MOST = 300;

    public int $uniqueFor = 1800;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public readonly string $shopId)
    {
        $this->delay(now()->addSeconds(self::GATHER_SECONDS));
    }

    public function uniqueId(): string
    {
        return $this->shopId;
    }

    public function handle(ReadProductsInCode $read, BuildCards $cards, TenantContext $tenant): void
    {
        if (! Features::enabled('enrichment.read_new_at_once', $this->shopId)) {
            return;
        }
        $new = $tenant->run($this->shopId, function () {
            if (! EnrichmentCodeReading::query()->exists()) {
                return collect();
            }

            return CatalogProduct::query()->whereNull('removed_at')
                ->whereNotIn('id', EnrichmentCodeReading::query()->select('product_id'))
                ->orderBy('id')->limit(self::AT_MOST + 1)->pluck('external_id', 'id');
        });
        if ($new->isEmpty() || $new->count() > self::AT_MOST) {
            return;
        }

        $run = $read->handle($this->shopId, array_map('strval', $new->keys()->all()));
        if ((int) ($run->output['facts'] ?? 0) === 0) {
            return;
        }
        // what they are reaches the search box and every model's card now
        event(new FactsDecided($this->shopId));
        $cards->handle($this->shopId, array_values(array_map('strval', $new->all())), RunTrigger::System);
    }
}
