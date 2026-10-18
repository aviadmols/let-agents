<?php

namespace App\Modules\Admin\Contracts;

use Carbon\CarbonInterface;

/**
 * Something the system wants the shop manager to know: a thing to do, something not set up yet, an
 * insight from the numbers, or a report of what ran. Never a model, a cost or a mechanism.
 */
final readonly class ShopNotice
{
    public const TODO = 'todo';

    public const SETUP = 'setup';

    public const INSIGHT = 'insight';

    public const REPORT = 'report';

    public const KINDS = [self::TODO, self::SETUP, self::INSIGHT, self::REPORT];

    /**
     * @param  string  $tone  warn, info, tip, good, bad or ''
     * @param  int|null  $count  how many things wait (14 questions, 38 products): the pill on the home's list
     */
    public function __construct(
        public string $key,
        public string $kind,
        public string $tone,
        public string $title,
        public ?string $body,
        public string $source,
        public ?CarbonInterface $at = null,
        public ?string $url = null,
        public ?string $action = null,
        public int $weight = 50,
        public ?int $count = null,
    ) {}
}
