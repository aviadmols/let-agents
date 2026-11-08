<?php

namespace App\Modules\Admin\Contracts;

use Carbon\CarbonInterface;

/**
 * A fix the owner approved and what it did since (Today's "You fixed it. It works."): what shoppers searched,
 * what they find now, and the counts from the day it was approved. Counts only, from what search recorded.
 */
final readonly class TodayWin
{
    /**
     * @param  string  $searched  what shoppers typed, as typed
     * @param  string  $finds  what they find now: a product's name, or the word it now means
     * @param  float|null  $sales  money in visits that searched it and ordered, null when none
     */
    public function __construct(
        public string $key,
        public string $searched,
        public string $finds,
        public CarbonInterface $approvedAt,
        public int $found,
        public int $adds,
        public ?float $sales = null,
        public ?string $picture = null,
    ) {}
}
