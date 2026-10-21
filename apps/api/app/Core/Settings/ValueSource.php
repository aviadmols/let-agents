<?php

namespace App\Core\Settings;

/**
 * Where a resolved feature or setting value came from. The admin UI shows it, so the
 * operator can tell "this shop is at 150 because we set it" from "150 is the default".
 */
enum ValueSource: string
{
    case Default = 'default';
    case Global = 'global';
    case Shop = 'shop';
    // the shop's plan holds it lower (PlanCeilings)
    case Plan = 'plan';
}
