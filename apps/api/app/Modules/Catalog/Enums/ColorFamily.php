<?php

namespace App\Modules\Catalog\Enums;

/**
 * The colours a shopper filters by: few and broad, so "כחול" finds navy, light blue and denim
 * alike. A shade belongs to one or two of them (ColorShade::families). The exact shade is kept
 * beside it for a precise search; the store's own name for display.
 */
enum ColorFamily: string
{
    case Black = 'black';
    case White = 'white';
    case Grey = 'grey';
    case Beige = 'beige';
    case Brown = 'brown';
    case Red = 'red';
    case Pink = 'pink';
    case Orange = 'orange';
    case Yellow = 'yellow';
    case Green = 'green';
    case Blue = 'blue';
    case Purple = 'purple';
    case Gold = 'gold';
    case Silver = 'silver';
    case Multi = 'multi';

    public function label(): string
    {
        return __('catalog::colors.families.'.$this->value);
    }

    /** The swatch a filter chip shows; none for several colours together. */
    public function hex(): ?string
    {
        return match ($this) {
            self::Black => '#111111',
            self::White => '#FFFFFF',
            self::Grey => '#8C8C8C',
            self::Beige => '#D8C3A5',
            self::Brown => '#6F4E37',
            self::Red => '#C62828',
            self::Pink => '#F48FB1',
            self::Orange => '#F57C00',
            self::Yellow => '#F5D90A',
            self::Green => '#2E8B57',
            self::Blue => '#1F5FBF',
            self::Purple => '#6A3D9A',
            self::Gold => '#C9A43B',
            self::Silver => '#C0C0C0',
            self::Multi => null,
        };
    }
}
