<?php

namespace App\Modules\Taxonomy\Support;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Search\Contracts\RelatedByTags;
use Illuminate\Support\Facades\Cache;

/** The search's similar products by the tags they share (Related), where the shop has tags in search. A few minutes kept. */
final class RelatedProducts implements RelatedByTags
{
    private const KEEP_SECONDS = 600;

    public function to(string $shopId, string $externalId, int $limit): array
    {
        if (! Features::enabled('taxonomy.in_search', $shopId)) {
            return [];
        }

        return Cache::remember("taxonomy:related:{$shopId}:{$externalId}:{$limit}", self::KEEP_SECONDS, fn (): array => app(TenantContext::class)->run($shopId, function () use ($externalId, $limit): array {
            $id = CatalogProduct::query()->whereNull('removed_at')->where('external_id', $externalId)->value('id');
            if ($id === null) {
                return [];
            }
            $found = (new Related)->to((string) $id, $limit);
            $external = CatalogProduct::query()->whereIn('id', array_column($found, 'id'))->pluck('external_id', 'id');

            return array_values(array_filter(array_map(fn (array $r): ?array => isset($external[$r['id']]) ? ['external_id' => (string) $external[$r['id']], 'score' => (float) $r['score']] : null, $found)));
        }));
    }
}
