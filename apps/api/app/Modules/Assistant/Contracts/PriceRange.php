<?php

namespace App\Modules\Assistant\Contracts;

/**
 * The price range a shopper wrote ("עד 500 ש״ח", "בין 1000 ל-2000", "under 300"), read the way the
 * store's search reads it, so the answer holds the same range the search filtered the products by.
 *
 * The Search module reads it and binds it here (it requires this module, not the other way round).
 * Without the Search module nothing is bound, and a question has no range.
 */
interface PriceRange
{
    /** @return array{min: float|null, max: float|null} both null when the text names no price */
    public function read(string $text): array;
}
