<?php

namespace App\Modules\Admin\Contracts;

/**
 * One line of "what is on and what is not" on the shop's home: a part of the system, its state in
 * the shop manager's words, and the way to the screen that changes it.
 */
final readonly class HomeStatus
{
    public const ON = 'on';

    public const OFF = 'off';

    /** On, but not doing its job yet: not connected on the site, not set up. */
    public const SETUP = 'setup';

    public function __construct(
        public string $key,
        public string $name,
        public string $state,
        public string $note,
        public ?string $url = null,
        public ?string $action = null,
        public int $order = 50,
    ) {}
}
