<?php

namespace App\Modules\Admin\Contracts;

/**
 * The shop the operator chose in the top bar, for modules that act on it (run an agent now)
 * without reaching into the panel's session.
 */
interface ChosenShop
{
    /** @return array{id: string, slug: string, name: string}|null null while every shop is shown */
    public function get(): ?array;
}
