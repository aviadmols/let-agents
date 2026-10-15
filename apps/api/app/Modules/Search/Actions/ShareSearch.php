<?php

namespace App\Modules\Search\Actions;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Search\Models\SearchShare;
use App\Modules\Search\Support\AgentCatalog;
use App\Modules\Search\Support\SpellingProductsForQuery;
use Illuminate\Support\Str;

/**
 * A shopper's search, handed to their own AI (ChatGPT, Claude, Perplexity…): the words, what they
 * were read as (a price, a colour, a size), the products found with what the store knows of each
 * (its approved details, its sizes and their stock), and the store's policies, kept as one
 * snapshot behind a short link. The shopper's AI reads it and advises; the purchase stays on the
 * store. Code only: no model is asked, and nothing about the shopper is kept.
 *
 * Prices and stock are the store's as of its last sync, and the page says when that was.
 */
final class ShareSearch
{
    /** Products in one snapshot, at most. */
    public const MAX_PRODUCTS = 24;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly SpellingProductsForQuery $spelling,
    ) {}

    /** @param list<string> $externalIds the products the shopper saw, best first; none to search again */
    public function handle(StoreConnection $connection, string $query, array $externalIds = []): SearchShare
    {
        $shopId = (string) $connection->shop_id;

        return $this->tenant->run($shopId, function () use ($connection, $shopId, $query, $externalIds): SearchShare {
            $query = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $query)), 0, 200);
            $limit = min(self::MAX_PRODUCTS, (int) Settings::get('search.ai_share_products', $shopId));
            $ids = array_slice(array_values(array_unique(array_filter(array_map('strval', $externalIds), fn (string $id): bool => preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $id) === 1))), 0, $limit);

            if ($ids === [] && $query !== '') {
                $ids = $this->spelling->find($shopId, $query, $limit);
            }
            // Only the store's own products, in the order sent.
            $known = array_flip(CatalogProduct::query()->whereIn('external_id', $ids)->whereNull('removed_at')->pluck('external_id')->map(fn ($id): string => (string) $id)->all());
            $ids = array_values(array_filter($ids, fn (string $id): bool => isset($known[$id])));

            $fingerprint = hash('sha256', $shopId."\n".$query."\n".implode(',', $ids));
            $kept = SearchShare::query()->where('fingerprint', $fingerprint)->where('created_at', '>', now()->subHour())->where('expires_at', '>', now())->latest()->first();

            if ($kept !== null) {
                return $kept;
            }

            return SearchShare::query()->create([
                'token' => Str::lower(Str::random(12)),
                'fingerprint' => $fingerprint,
                'query' => $query,
                'snapshot' => [
                    'store' => AgentCatalog::store($connection),
                    'query' => $query,
                    'read' => AgentCatalog::read($shopId, $query),
                    'products' => AgentCatalog::products($ids),
                    'policies' => AgentCatalog::policies(),
                    'as_of' => AgentCatalog::asOf($ids),
                ],
                'expires_at' => now()->addDays((int) Settings::get('search.ai_share_days', $shopId)),
            ]);
        });
    }
}
