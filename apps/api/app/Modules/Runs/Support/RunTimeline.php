<?php

namespace App\Modules\Runs\Support;

use App\Modules\Admin\Contracts\TimelineEntry;
use App\Modules\Admin\Contracts\TimelineSource;
use App\Modules\Runs\Enums\RunStatus;
use App\Modules\Runs\Filament\Operator\Pages\ProcessLog;
use App\Modules\Runs\Models\Run;
use App\Modules\Tenancy\Models\Shop;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * What this module brings to the timeline: every run of an agent in the shop, with its summary line
 * and what it cost; the live agents (an answer to a shopper, a query's embedding) as one line a day,
 * so a busy shop does not drown the rest.
 */
final class RunTimeline implements TimelineSource
{
    private const MAX = 400;

    /** A summary line the shop does not see: a model, a cost, tokens, or the machinery under the scan. */
    private const NOT_FOR_SHOP = '/\$|\btokens?\b|gpt-|claude|gemini|openai|anthropic|מודל|model|וקטור|vector|batch|באצ|בקשות|\brequests?\b|characters|תווים|prompt|פרומפט/iu';

    /** The kind an action's runs are shown as, by the longest matching prefix. */
    private const KINDS = [
        'improvement.' => TimelineEntry::INSIGHT,
        'assistant.review_asks' => TimelineEntry::INSIGHT,
        'assistant.' => TimelineEntry::KNOWLEDGE,
        'catalog.' => TimelineEntry::SCAN,
        'enrichment.' => TimelineEntry::SCAN,
        'retrieval.' => TimelineEntry::SCAN,
        'search.' => TimelineEntry::SCAN,
        'analytics.' => TimelineEntry::SCAN,
        'connections.' => TimelineEntry::SYSTEM,
    ];

    public function entries(Shop $shop, CarbonInterface $since): array
    {
        $live = array_keys(Pipeline::STAGES['live']);
        $entries = [];

        $runs = Run::query()->forShop($shop->id)->where('started_at', '>=', $since)->whereNotIn('action', $live)
            ->orderByDesc('started_at')->limit(self::MAX)->get();

        foreach ($runs as $run) {
            $summary = $run->summary();
            $failed = $run->status === RunStatus::Failed;

            if ($summary === null && ! $failed) {
                continue;
            }

            $entries[] = new TimelineEntry(
                key: 'run.'.$run->id,
                kind: self::kind($run->action),
                at: $run->started_at,
                title: $run->agentLabel().': '.($summary ?? $run->status->label()),
                lines: array_values(array_filter([$failed && $run->error !== null ? mb_substr($run->error, 0, 300) : null, $run->durationForHumans()])),
                url: ProcessLog::getUrl(['step' => $run->action, 'run' => $run->id], panel: 'operator'),
                who: $run->trigger->label(),
                costUsd: $run->cost_usd === null ? null : (float) $run->cost_usd,
                tone: $failed ? 'bad' : '',
                forShop: ! $failed && $summary !== null && preg_match(self::NOT_FOR_SHOP, $summary) !== 1,
                shopTitle: $summary,
            );
        }

        $days = Run::query()->forShop($shop->id)->where('started_at', '>=', $since)->whereIn('action', $live)
            ->selectRaw('date(started_at) as day, count(*) as n, sum(cost_usd) as cost')->groupBy('day')->get();

        foreach ($days as $day) {
            $entries[] = new TimelineEntry(
                key: 'runs.live.'.$day->day,
                kind: TimelineEntry::SYSTEM,
                at: Carbon::parse((string) $day->day)->endOfDay(),
                title: __('runs::timeline.live', ['n' => (int) $day->n]),
                url: ProcessLog::getUrl(['step' => 'assistant.answer'], panel: 'operator'),
                costUsd: (float) $day->cost,
                forShop: false,
            );
        }

        return $entries;
    }

    private static function kind(string $action): string
    {
        $best = TimelineEntry::SYSTEM;
        $length = 0;

        foreach (self::KINDS as $prefix => $kind) {
            if (str_starts_with($action, $prefix) && strlen($prefix) > $length) {
                [$best, $length] = [$kind, strlen($prefix)];
            }
        }

        return $best;
    }
}
