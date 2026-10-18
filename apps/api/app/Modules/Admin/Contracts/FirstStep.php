<?php

namespace App\Modules\Admin\Contracts;

/**
 * One of the shop's first steps on its home (the designer's "5 first steps"): what to do, why, and
 * where. A step is done when the thing itself is done (an answer approved, a value saved), never
 * when its button is pressed; the module that owns it says so.
 */
final readonly class FirstStep
{
    /** @param  bool  $external  the button opens the store itself, in a new tab */
    public function __construct(
        public string $key,
        public int $order,
        public string $title,
        public string $body,
        public string $button,
        public ?string $url,
        public bool $done,
        public bool $external = false,
    ) {}
}
