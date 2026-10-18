<?php

namespace App\Modules\Admin\Contracts;

/**
 * One row of a list on the shop's analytics screen (a search word, a product, a question, an IP): what
 * it is, its number, a line under it, a pill, a picture, and what can be done with it there.
 */
final readonly class ReportRow
{
    /**
     * @param  string  $key  stable within its list, sent back with an action
     * @param  int|float  $value  what the list is sorted by
     * @param  string  $tone  '', good, warn or bad: the pill's
     * @param  list<string>  $actions  what the row offers: synonym, block, unblock, shown
     */
    public function __construct(
        public string $key,
        public string $title,
        public int|float $value,
        public string $sub = '',
        public ?string $pill = null,
        public string $tone = '',
        public ?string $thumb = null,
        public array $actions = [],
        public bool $ltr = false,
    ) {}
}
