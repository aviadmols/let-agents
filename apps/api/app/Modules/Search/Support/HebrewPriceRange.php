<?php

namespace App\Modules\Search\Support;

use App\Modules\Assistant\Contracts\PriceRange;

final class HebrewPriceRange implements PriceRange
{
    public function read(string $text): array
    {
        [, $min, $max] = HebrewSearch::priceRange(mb_substr($text, 0, 120));

        return ['min' => $min, 'max' => $max];
    }
}
