<?php

namespace App\Modules\Ai\Events;

/**
 * A model call was refused for a monthly cap: the platform's (no shop) or one shop's. Raised on every refusal, so who
 * listens keeps only what it needs (Alerts tells the team once).
 */
final class SpendCapHit
{
    public function __construct(
        public readonly float $spentUsd,
        public readonly float $capUsd,
        public readonly ?string $shopId = null,
    ) {}
}
