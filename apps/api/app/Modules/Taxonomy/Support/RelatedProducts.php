<?php

namespace App\Modules\Taxonomy\Support;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Search\Contracts\RelatedByTags;
use App\Modules\Taxonomy\Actions\ApplyRule;
use App\Modules\Taxonomy\Models\TaxonomyPair;
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

        $version = (int) Cache::get(ApplyRule::RELATED_VERSION.$shopId, 0);

        return Cache::remember("taxonomy:related:{$shopId}:{$version}:{$externalId}:{$limit}", self::KEEP_SECONDS, fn (): array => app(TenantContext::class)->run($shopId, function () use ($externalId, $limit): array {
            $id = CatalogProduct::query()->whereNull('removed_at')->where('external_id', $externalId)->value('id');
            if ($id === null) {
                return [];
            }
            $found = (new Related)->to((string) $id, $limit);
            $external = CatalogProduct::query()->whereIn('id', array_column($found, 'id'))->pluck('external_id', 'id');

            return array_values(array_filter(array_map(fn (array $r): ?array => isset($external[$r['id']]) ? ['external_id' => (string) $external[$r['id']], 'score' => (float) $r['score']] : null, $found)));
        }));
    }

    public function pairs(string $shopId, string $externalId): array
    {
        return app(TenantContext::class)->run($shopId, function () use ($externalId): array {
            $id = CatalogProduct::query()->whereNull('removed_at')->where('external_id', $externalId)->value('id');
            if ($id === null) {
                return ['apart' => [], 'alike' => []];
            }
            $pairs = TaxonomyPair::of((string) $id);
            $external = CatalogProduct::query()->whereNull('removed_at')->whereIn('id', [...$pairs['apart'], ...$pairs['alike']])->pluck('external_id', 'id');
            $named = fn (array $ids): array => array_values(array_filter(array_map(fn (string $id): ?string => isset($external[$id]) ? (string) $external[$id] : null, $ids)));

            return ['apart' => $named($pairs['apart']), 'alike' => $named($pairs['alike'])];
        });
    }
}
