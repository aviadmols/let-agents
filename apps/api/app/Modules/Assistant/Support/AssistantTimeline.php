<?php

namespace App\Modules\Assistant\Support;

use App\Modules\Admin\Contracts\TimelineEntry;
use App\Modules\Admin\Contracts\TimelineSource;
use App\Modules\Assistant\Filament\Operator\Pages\ShopQuestions;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Tenancy\Models\Shop;
use Carbon\CarbonInterface;

/** What this module brings to the timeline: the answers the shop's team wrote to shoppers' questions. */
final class AssistantTimeline implements TimelineSource
{
    private const MAX = 100;

    public function entries(Shop $shop, CarbonInterface $since): array
    {
        $entries = [];
        $answers = AssistantAnswer::query()->with(['product:id,title', 'content:id,title'])
            ->where('source', 'team')->where('updated_at', '>=', $since)
            ->orderByDesc('updated_at')->limit(self::MAX)->get();

        foreach ($answers as $answer) {
            $page = $answer->product?->title ?? $answer->content?->title;
            $entries[] = new TimelineEntry(
                key: 'assistant.team.'.$answer->id,
                kind: TimelineEntry::USER,
                at: $answer->updated_at,
                title: __('assistant::timeline.team_answered', ['question' => mb_substr((string) $answer->question, 0, 120)]),
                lines: array_values(array_filter([mb_substr((string) $answer->answer, 0, 240), $page === null ? null : __('assistant::timeline.on_page', ['page' => $page])])),
                url: ShopQuestions::getUrl(panel: 'operator'),
                who: __('assistant::timeline.team'),
            );
        }

        return $entries;
    }
}
