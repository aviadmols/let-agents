<?php

namespace App\Modules\Admin\Contracts;

/**
 * One module's row in "what each module brought": how often shoppers used it and, where it is
 * measured, what went to the cart and what was bought through it. Null where it is not measured.
 */
final readonly class ModuleFigure
{
    public function __construct(
        public string $key,
        public string $name,
        public int $uses,
        public ?int $previousUses = null,
        public ?int $adds = null,
        public ?int $orders = null,
        public ?float $revenue = null,
        public ?string $url = null,
        public int $order = 50,
    ) {}
}
