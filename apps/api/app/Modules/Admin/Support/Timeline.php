<?php

namespace App\Modules\Admin\Support;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Contracts\TimelineEntry;
use App\Modules\Admin\Contracts\TimelineSource;
use App\Modules\Tenancy\Models\Shop;

/**
 * The shop's timeline, gathered from every module that tagged a TimelineSource: newest first, grouped
 * by day, with what the period cost in AI. Read inside the shop, so every source sees its own rows.
 */
final class Timeline
{
    private const MAX = 600;

    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @param  string  $kind  one of TimelineEntry::KINDS, or '' for all
     * @return list<TimelineEntry> newest first
     */
    public function entries(Shop $shop, int $days, string $kind = ''): array
    {
        $since = now()->subDays(max(1, $days))->startOfDay();
        $entries = [];

        foreach (app()->tagged(TimelineSource::TAG) as $source) {
            array_push($entries, ...$this->tenant->run($shop->id, fn (): array => $source->entries($shop, $since)));
        }

        if ($kind !== '') {
            $entries = array_values(array_filter($entries, fn (TimelineEntry $e): bool => $e->kind === $kind));
        }

        usort($entries, fn (TimelineEntry $a, TimelineEntry $b): int => [$b->at->getTimestamp(), $a->key] <=> [$a->at->getTimestamp(), $b->key]);

        return array_slice($entries, 0, self::MAX);
    }

    /**
     * @param  list<TimelineEntry>  $entries
     * @return array<string, list<TimelineEntry>> by day (Y-m-d in the shop's hours), newest day first
     */
    public static function byDay(array $entries, string $timezone = 'Asia/Jerusalem'): array
    {
        $days = [];

        foreach ($entries as $entry) {
            $days[$entry->at->copy()->timezone($timezone)->format('Y-m-d')][] = $entry;
        }

        return $days;
    }

    /** @param  list<TimelineEntry>  $entries */
    public static function cost(array $entries): float
    {
        return round(array_sum(array_map(fn (TimelineEntry $e): float => $e->costUsd ?? 0.0, $entries)), 4);
    }

    /**
     * @param  list<TimelineEntry>  $entries
     * @return array<string, int> entries per kind, in the order of TimelineEntry::KINDS
     */
    public static function counts(array $entries): array
    {
        $counts = array_fill_keys(TimelineEntry::KINDS, 0);

        foreach ($entries as $entry) {
            $counts[$entry->kind] = ($counts[$entry->kind] ?? 0) + 1;
        }

        return $counts;
    }
}
