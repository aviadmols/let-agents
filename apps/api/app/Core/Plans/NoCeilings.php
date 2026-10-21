<?php

namespace App\Core\Plans;

/** No module keeps plans: nothing is above what the shop and the operator set. */
final class NoCeilings implements PlanCeilings
{
    public function for(string $shopId): array
    {
        return ['features' => [], 'settings' => []];
    }
}
