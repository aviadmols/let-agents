<?php

namespace App\Modules\Admin\Contracts;

use App\Modules\Tenancy\Models\Shop;

/** What a module brings to the shop's first steps. Tagged 'admin.first_steps', asked inside the shop. */
interface FirstSteps
{
    public const STEPS_TAG = 'admin.first_steps';

    /** @return list<FirstStep> */
    public function steps(Shop $shop): array;
}
