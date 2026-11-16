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

    public function holds(string $shopId, string $words): bool
    {
        if (trim($words) === '') {
            return false;
        }
        $found = $this->search->handle($shopId, $words, ['product'], count: false, perGroup: 1, meaning: false);

        // held whole, never by a looser search that left a word out, nor by what a sentence was read as asking for: a wish
        // ("מחפש מתנה לאשתי ליום נישואין, משהו עדין עד 2000 שקל") was answered "the cheapest in the price" with one ring
        // (Rocks QA, 16.11), its words held by none
        return (int) ($found['held'] ?? 0) > 0 && ! isset($found['loosened']) && ! isset($found['sentence']);
    }

    public function namesNothing(string $shopId, string $words): bool
    {
        if (trim($words) === '') {
            return true;
        }
        $found = $this->search->handle($shopId, $words, ['product'], count: false, perGroup: 1, meaning: false);

        // none holds what was searched, the sentence read as what it asks for included, and no looser search found the kind
        return (int) ($found['held'] ?? 0) === 0 && ! isset($found['loosened']);
    }

    public function all(string $shopId, string $question): array
    {
        $found = $this->search->handle($shopId, $question, ['product'], count: false, perGroup: self::ALL, meaning: false);

        return array_values(array_unique(array_map('strval', array_column($found['groups']['product'] ?? [], 'external_id'))));
    }
}
