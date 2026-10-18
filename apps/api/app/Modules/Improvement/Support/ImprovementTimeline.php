<?php

namespace App\Modules\Improvement\Support;

use App\Modules\Admin\Contracts\TimelineEntry;
use App\Modules\Admin\Contracts\TimelineSource;
use App\Modules\Admin\Models\User;
use App\Modules\Improvement\Filament\Operator\Pages\SiteSuggestions;
use App\Modules\Improvement\Models\ImprovementProposal;
use App\Modules\Tenancy\Models\Shop;
use Carbon\CarbonInterface;

/** What this module brings to the timeline: the suggestions the team decided on. The reviews themselves are runs. */
final class ImprovementTimeline implements TimelineSource
{
    private const MAX = 100;

    public function entries(Shop $shop, CarbonInterface $since): array
    {
        $entries = [];
        $proposals = ImprovementProposal::query()->whereNotNull('decided_at')->where('decided_at', '>=', $since)
            ->whereIn('status', [ImprovementProposal::APPLIED, ImprovementProposal::APPROVED, ImprovementProposal::REJECTED])
            ->orderByDesc('decided_at')->limit(self::MAX)->get();
        $names = User::query()->whereIn('id', $proposals->pluck('decided_by')->filter()->unique())->pluck('name', 'id');

        foreach ($proposals as $proposal) {
            $entries[] = new TimelineEntry(
                key: 'improvement.decided.'.$proposal->id,
                kind: TimelineEntry::USER,
                at: $proposal->decided_at,
                title: __('improvement::timeline.decided.'.$proposal->status, ['title' => mb_substr((string) $proposal->title, 0, 120)]),
                url: SiteSuggestions::getUrl(['shop' => $shop->id], panel: 'operator'),
                who: $proposal->decided_by === null ? null : $names[$proposal->decided_by] ?? null,
            );
        }

        return $entries;
    }
}
