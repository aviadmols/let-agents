<?php

namespace App\Modules\Admin\Contracts;

/**
 * One of the four cards at the top of the shop's home (the designer's panel): what shoppers asked,
 * how much of the month's AI allowance is used, how much of the catalogue and the site was read.
 * The merchant's words only: a share, never an amount; a count, never a model.
 *
 * @param  array{0: int|float, 1: int|float}|null  $change  this period and the one before, for the change pill
 * @param  int|null  $bar  a share, 0 to 100, drawn as a bar under the number
 * @param  string|null  $pill  a short state, in an outline pill ("last scan 12 hours ago")
 */
final readonly class HomeCard
{
    public function __construct(
        public string $key,
        public int $order,
        public string $label,
        public string $value,
        public ?string $of = null,
        public ?string $note = null,
        public ?array $change = null,
        public ?int $bar = null,
        public ?string $pill = null,
        public string $tone = '',
        public ?string $url = null,
    ) {}
}
