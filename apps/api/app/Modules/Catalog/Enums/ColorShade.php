<?php

namespace App\Modules\Catalog\Enums;

/**
 * A named shade, between the store's own name ("Ocean Mist", "שמנת חלבי") and the family a
 * shopper filters by. A shade on the border of two families sits in both: turquoise is blue and
 * green, khaki green and beige, so a shopper who picks either one finds it.
 */
enum ColorShade: string
{
    case Black = 'black';
    case Charcoal = 'charcoal';
    case DarkGrey = 'dark_grey';
    case Grey = 'grey';
    case LightGrey = 'light_grey';
    case White = 'white';
    case OffWhite = 'off_white';
    case Cream = 'cream';
    case Beige = 'beige';
    case Sand = 'sand';
    case Camel = 'camel';
    case Taupe = 'taupe';
    case Nude = 'nude';
    case Brown = 'brown';
    case Chocolate = 'chocolate';
    case Khaki = 'khaki';
    case Olive = 'olive';
    case Green = 'green';
    case DarkGreen = 'dark_green';
    case LightGreen = 'light_green';
    case Mint = 'mint';
    case Turquoise = 'turquoise';
    case Petrol = 'petrol';
    case LightBlue = 'light_blue';
    case Blue = 'blue';
    case RoyalBlue = 'royal_blue';
    case Navy = 'navy';
    case Denim = 'denim';
    case Purple = 'purple';
    case Lilac = 'lilac';
    case Burgundy = 'burgundy';
    case Red = 'red';
    case Coral = 'coral';
    case Orange = 'orange';
    case Rust = 'rust';
    case Mustard = 'mustard';
    case Yellow = 'yellow';
    case Pink = 'pink';
    case LightPink = 'light_pink';
    case Fuchsia = 'fuchsia';
    case Peach = 'peach';
    case Gold = 'gold';
    case Silver = 'silver';
    case RoseGold = 'rose_gold';
    case Multi = 'multi';

    public function label(): string
    {
        return __('catalog::colors.shades.'.$this->value);
    }

    /** @return list<ColorFamily> the first is the one it is shown under */
    public function families(): array
    {
        return match ($this) {
            self::Black => [ColorFamily::Black],
            self::Charcoal => [ColorFamily::Grey, ColorFamily::Black],
            self::DarkGrey, self::Grey, self::LightGrey => [ColorFamily::Grey],
            self::White => [ColorFamily::White],
            self::OffWhite, self::Cream => [ColorFamily::White, ColorFamily::Beige],
            self::Beige, self::Sand => [ColorFamily::Beige],
            self::Camel, self::Taupe => [ColorFamily::Beige, ColorFamily::Brown],
            self::Nude => [ColorFamily::Beige, ColorFamily::Pink],
            self::Brown, self::Chocolate => [ColorFamily::Brown],
            self::Khaki => [ColorFamily::Green, ColorFamily::Beige],
            self::Olive, self::Green, self::DarkGreen, self::LightGreen, self::Mint => [ColorFamily::Green],
            self::Turquoise, self::Petrol => [ColorFamily::Blue, ColorFamily::Green],
            self::LightBlue, self::Blue, self::RoyalBlue, self::Navy, self::Denim => [ColorFamily::Blue],
            self::Purple, self::Lilac => [ColorFamily::Purple],
            self::Burgundy, self::Red => [ColorFamily::Red],
            self::Coral => [ColorFamily::Orange, ColorFamily::Pink],
            self::Orange => [ColorFamily::Orange],
            self::Rust => [ColorFamily::Orange, ColorFamily::Brown],
            self::Mustard, self::Yellow => [ColorFamily::Yellow],
            self::Pink, self::LightPink => [ColorFamily::Pink],
            self::Fuchsia => [ColorFamily::Pink, ColorFamily::Purple],
            self::Peach => [ColorFamily::Orange, ColorFamily::Pink],
            self::Gold => [ColorFamily::Gold, ColorFamily::Yellow],
            self::Silver => [ColorFamily::Silver, ColorFamily::Grey],
            self::RoseGold => [ColorFamily::Gold, ColorFamily::Pink],
            self::Multi => [ColorFamily::Multi],
        };
    }

    /** A typical swatch: what a store without its own swatch is shown, and what "a similar colour" is measured from. */
    public function hex(): ?string
    {
        return match ($this) {
            self::Black => '#111111',
            self::Charcoal => '#36454F',
            self::DarkGrey => '#4A4A4A',
            self::Grey => '#8C8C8C',
            self::LightGrey => '#C8C8C8',
            self::White => '#FFFFFF',
            self::OffWhite => '#F5F2E8',
            self::Cream => '#F3E9D2',
            self::Beige => '#D8C3A5',
            self::Sand => '#C2B280',
            self::Camel => '#C19A6B',
            self::Taupe => '#8B7D6B',
            self::Nude => '#E3BC9A',
            self::Brown => '#6F4E37',
            self::Chocolate => '#3D2314',
            self::Khaki => '#8A865D',
            self::Olive => '#708238',
            self::Green => '#2E8B57',
            self::DarkGreen => '#0B4F2C',
            self::LightGreen => '#90D890',
            self::Mint => '#98E2C6',
            self::Turquoise => '#30D5C8',
            self::Petrol => '#00707A',
            self::LightBlue => '#9CC9E8',
            self::Blue => '#1F5FBF',
            self::RoyalBlue => '#2B4CC4',
            self::Navy => '#1B2A4A',
            self::Denim => '#5B7BA0',
            self::Purple => '#6A3D9A',
            self::Lilac => '#C8A2C8',
            self::Burgundy => '#800020',
            self::Red => '#C62828',
            self::Coral => '#FF7F50',
            self::Orange => '#F57C00',
            self::Rust => '#B7410E',
            self::Mustard => '#D4A017',
            self::Yellow => '#F5D90A',
            self::Pink => '#F48FB1',
            self::LightPink => '#F8C8D4',
            self::Fuchsia => '#D81B60',
            self::Peach => '#FFCBA4',
            self::Gold => '#C9A43B',
            self::Silver => '#C0C0C0',
            self::RoseGold => '#B76E79',
            self::Multi => null,
        };
    }
}
