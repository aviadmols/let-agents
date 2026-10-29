<?php

namespace App\Modules\Taxonomy\Jobs;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Events\CatalogProductsChanged;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Taxonomy\Actions\AssignTags;
use App\Modules\Taxonomy\Models\TaxonomyAssignment;
use App\Modules\Taxonomy\Models\TaxonomySubject;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

/**
 * Tags what a shop changed, by itself (taxonomy.tagging): a new or edited product within minutes of its sync, and
 * each night every product and post whose words changed since it was tagged. Only what changed is sent to a model
 * (AssignTags): one whose words are as they were costs nothing and opens no run. The products given new tags are
 * told to the search (CatalogProductsChanged), which reads tags when it builds its index; that event brings this job
 * back, which then finds nothing waiting.
 *
 * Products waiting gather in the cache (WAITING) while the job waits its few minutes, so a store that changes forty
 * products in a row is tagged in one run.
 */
final class TagChangesJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const WAITING = 'taxonomy:waiting:';

    /** Minutes a change waits for the ones after it. */
    public const GATHER = 3;

    /** Under the queue's retry_after (1800), or a long night would be started twice; what is cut short waits for the next. */
    public int $timeout = 1500;

    public int $tries = 1;

    public int $uniqueFor = 1800;

    /** @param  bool  $everything  every product and post that changed (the night), else the products waiting */
    public function __construct(public readonly string $shopId, public readonly bool $everything = false) {}

    public function uniqueId(): string
    {
        return $this->shopId.':'.($this->everything ? 'all' : 'products');
    }

    /** The products a sync changed, by their store ids, kept for the job, and the job asked for. */
    public static function products(string $shopId, array $externalIds): void
    {
        if ($externalIds === [] || ! Features::enabled('taxonomy.tagging', $shopId)) {
            return;
        }
        $key = self::WAITING.$shopId;
        Cache::lock($key.':lock', 10)->block(5, function () use ($key, $externalIds): void {
            Cache::put($key, array_values(array_unique([...(array) Cache::get($key, []), ...array_map('strval', $externalIds)])), now()->addDay());
        });
        self::dispatch($shopId)->delay(now()->addMinutes(self::GATHER));
    }

    public function handle(AssignTags $assign, TenantContext $tenant): void
    {
        if (! Features::enabled('taxonomy.tagging', $this->shopId)) {
            return;
        }

        $productIds = null;
        if (! $this->everything) {
            $external = (array) Cache::pull(self::WAITING.$this->shopId, []);
            if ($external === []) {
                return;
            }
            $productIds = $tenant->run($this->shopId, fn (): array => CatalogProduct::query()->whereIn('external_id', $external)->pluck('id')->map(fn ($id): string => (string) $id)->all());
            if ($productIds === []) {
                return;
            }
        }

        if ($assign->waiting($this->shopId, $this->everything, $productIds) === 0) {
            return;
        }

        $started = now()->subSecond();
        $assign->handle($this->shopId, posts: $this->everything, productIds: $productIds, trigger: $this->everything ? RunTrigger::Schedule : RunTrigger::System);

        // what was tagged now, for the search to read
        $tagged = $tenant->run($this->shopId, fn (): array => CatalogProduct::query()->whereIn('id', TaxonomySubject::query()
            ->where('subject_type', TaxonomyAssignment::PRODUCT)->where('updated_at', '>=', $started)->select('subject_id'))
            ->pluck('external_id')->map(fn ($id): string => (string) $id)->all());
        if ($tagged !== []) {
            event(new CatalogProductsChanged($this->shopId, $tagged));
        }
    }
}
