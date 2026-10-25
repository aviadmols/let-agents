<?php

namespace App\Modules\Tenancy\Enums;

enum ShopStatus: string
{
    /** Widget runs, API answers, background work runs. */
    case Active = 'active';

    /** Temporarily off: the API refuses the shop's keys and the widget hides. Data is kept. */
    case Paused = 'paused';

    /** Off until the operator turns it back on. */
    case Disabled = 'disabled';

    /**
     * A store someone asked to see Lets on (the Demo module): its storefront answers on the demo page, and no
     * scheduled work touches it (every schedule takes active shops). Deleted, with all it holds, when the demo ends.
     */
    case Demo = 'demo';

    public function label(): string
    {
        return __("tenancy::shops.statuses.{$this->value}");
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Paused => 'warning',
            self::Disabled => 'danger',
            self::Demo => 'info',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return array_combine(
            array_map(fn (self $s) => $s->value, self::cases()),
            array_map(fn (self $s) => $s->label(), self::cases()),
        );
    }
}
