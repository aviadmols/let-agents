<?php

namespace App\Modules\Catalog\Enums;

/**
 * What is printed or woven on a garment, apart from its colours: a navy-and-white striped shirt
 * is blue and white, and striped. Kept per variant, since one shirt is often sold plain and striped.
 */
enum FabricPattern: string
{
    case Solid = 'solid';
    case Stripes = 'stripes';
    case Checks = 'checks';
    case Floral = 'floral';
    case Dots = 'dots';
    case Animal = 'animal';
    case Camo = 'camo';
    case TieDye = 'tie_dye';
    case Print = 'print';

    public function label(): string
    {
        return __('catalog::colors.patterns.'.$this->value);
    }
}
