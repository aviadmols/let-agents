<?php

namespace App\Modules\Enrichment\Events;

/**
 * Facts of a shop's products were written or decided (a reading's answers applied, a review's verdicts):
 * what search knows of them may have changed, so its index is built again.
 */
final class FactsDecided
{
    public function __construct(public readonly string $shopId) {}
}
