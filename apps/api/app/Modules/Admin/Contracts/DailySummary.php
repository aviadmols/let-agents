<?php

namespace App\Modules\Admin\Contracts;

use Carbon\CarbonInterface;

/**
 * One day's report of one kind for one shop, as a card (DailySummaries).
 *
 * @param  string  $headline  the day in a line ("ציון 64 מתוך 100 · 67 שאלות")
 * @param  list<array{label: string, items: list<string>, tone?: string}>  $lists  what the day did, each list with its heading
 * @param  string  $tone  good, warn, bad or '' for the headline
 */
final readonly class DailySummary
{
    public function __construct(
        public string $kind,
        public string $shopId,
        public CarbonInterface $day,
        public string $headline,
        public ?string $summary = null,
        public array $lists = [],
        public ?string $url = null,
        public string $tone = '',
    ) {}
}
