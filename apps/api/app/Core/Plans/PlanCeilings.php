<?php

namespace App\Core\Plans;

/**
 * What a shop's plan allows, above whatever the shop or the operator set: a feature the plan does
 * not include is off, a number is capped at the plan's, a choice is the plan's. A ceiling never
 * turns anything on or raises anything, so a shop's own choices stay its own within its plan.
 * The kernel asks; the module that keeps plans answers (bound in its provider).
 */
interface PlanCeilings
{
    /**
     * The ceilings for one shop; both lists empty when the shop has no plan.
     *
     * @return array{features: array<string, bool>, settings: array<string, int|float|string|bool>}
     */
    public function for(string $shopId): array;
}
