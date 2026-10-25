<?php

namespace App\Modules\Search\Support;

use App\Modules\Assistant\Contracts\ProductsNamed;
use App\Modules\Search\Actions\SearchCatalog;

/** The products the search finds for the words, by spelling only: what holds them, not what is close. */
final class SpellingProductsNamed implements ProductsNamed
{
    /** Enough to hold every product a shopper could have been shown. */
    private const ALL = 1000;

    public function __construct(private readonly SearchCatalog $search) {}

    public function among(string $shopId, string $question, array $externalIds): array
    {
        $named = array_flip($this->all($shopId, $question));

        return array_values(array_filter(array_map('strval', $externalIds), fn (string $id): bool => isset($named[$id])));
    }

    public function all(string $shopId, string $question): array
    {
        $found = $this->search->handle($shopId, $question, ['product'], count: false, perGroup: self::ALL, meaning: false);

        return array_values(array_unique(array_map('strval', array_column($found['groups']['product'] ?? [], 'external_id'))));
    }
}
