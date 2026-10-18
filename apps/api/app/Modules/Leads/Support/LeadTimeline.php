<?php

namespace App\Modules\Leads\Support;

use App\Modules\Admin\Contracts\TimelineEntry;
use App\Modules\Admin\Contracts\TimelineSource;
use App\Modules\Leads\Filament\Operator\Pages\LeadList;
use App\Modules\Leads\Models\Lead;
use App\Modules\Tenancy\Models\Shop;
use Carbon\CarbonInterface;

/** What this module brings to the timeline: every lead that left its details, by its masked contact. */
final class LeadTimeline implements TimelineSource
{
    private const MAX = 200;

    public function entries(Shop $shop, CarbonInterface $since): array
    {
        $entries = [];

        foreach (Lead::query()->where('created_at', '>=', $since)->orderByDesc('created_at')->limit(self::MAX)->get() as $lead) {
            $entries[] = new TimelineEntry(
                key: 'lead.'.$lead->id,
                kind: TimelineEntry::LEAD,
                at: $lead->created_at,
                title: __('leads::timeline.lead', ['contact' => $lead->contact_masked]),
                lines: array_values(array_map(fn ($v): string => mb_substr((string) $v, 0, 200), array_filter((array) ($lead->answers ?? [])))),
                url: LeadList::getUrl(panel: 'operator'),
            );
        }

        return $entries;
    }
}
