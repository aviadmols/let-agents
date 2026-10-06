<?php

namespace App\Modules\Retrieval\Candidates;

use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Retrieval\Contracts\Candidate;
use App\Modules\Retrieval\Contracts\CandidateSource;
use App\Modules\Retrieval\Support\Purchases;

/** Products that shared an order with this one, from live orders and the store's history. */
final class BoughtTogether implements CandidateSource
{
    private ?Purchases $purchases = null;

    /** @var array<string, string> external ID => catalogue ID */
    private array $ids = [];

    public function key(): string
    {
        return 'bought_together';
    }

    public function prepare(string $shopId): void
    {
        $this->purchases = Purchases::read($shopId);
        $this->ids = CatalogProduct::query()->active()->pluck('id', 'external_id')->map(fn ($id): string => (string) $id)->all();
    }

    public function candidates(CatalogProduct $product, int $limit): array
    {
        $found = [];

        foreach ($this->purchases?->partnersOf($product->external_id, $limit) ?? [] as $partner) {
            $id = $this->ids[$partner['external_id']] ?? null;

            if ($id !== null) {
                // Orders together count most; lift breaks ties between two equally common pairs.
                $found[] = new Candidate($id, $this->key(), $partner['together'] + min($partner['lift'], 10) / 10, [
                    'orders_together' => $partner['together'],
                    'lift' => $partner['lift'],
                ]);
            }
        }

        return $found;
    }

    /** What the panel says about the run: how much evidence there was. @return array<string, int> */
    public function evidence(): array
    {
        return ['orders' => $this->purchases?->totalOrders() ?? 0, 'baskets' => $this->purchases?->baskets() ?? 0];
    }
}
