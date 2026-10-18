<?php

namespace App\Modules\Admin\Contracts;

use Carbon\CarbonInterface;

/**
 * One thing that happened in a shop, on the operator's timeline: a scan, a product that came in, a
 * page read into the knowledge, something a user did, an insight review, a lead. With what it cost
 * in AI when a model was asked: the timeline is the operator's, who sees costs.
 */
final readonly class TimelineEntry
{
    public const SCAN = 'scan';

    public const CATALOG = 'catalog';

    public const KNOWLEDGE = 'knowledge';

    public const USER = 'user';

    public const INSIGHT = 'insight';

    public const LEAD = 'lead';

    public const SYSTEM = 'system';

    public const KINDS = [self::SCAN, self::CATALOG, self::KNOWLEDGE, self::USER, self::INSIGHT, self::LEAD, self::SYSTEM];

    /**
     * @param  list<string>  $lines  the details under the title, shown when it is opened
     * @param  string|null  $url  where the operator goes for the whole thing
     * @param  string|null  $who  who did it: a user's name, or how it was triggered
     * @param  string  $tone  '' or 'bad' (something failed)
     */
    public function __construct(
        public string $key,
        public string $kind,
        public CarbonInterface $at,
        public string $title,
        public array $lines = [],
        public ?string $url = null,
        public ?string $who = null,
        public ?float $costUsd = null,
        public string $tone = '',
    ) {}
}
