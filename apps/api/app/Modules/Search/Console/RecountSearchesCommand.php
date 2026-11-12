<?php

namespace App\Modules\Search\Console;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Search\Models\SearchLog;
use App\Modules\Search\Models\SearchTerm;
use App\Modules\Tenancy\Enums\ShopStatus;
use App\Modules\Tenancy\Models\Shop;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * search:recount — the days counted before the same words from the same address on the same day were one search
 * (CountSearch, 12.11), counted again: each word's searches lose those sent again from an address already counted that
 * day, as the log shows them. Only the days the log still holds, only up to yesterday, and each day once a shop
 * (search.recounted_until): run again, it changes nothing.
 */
final class RecountSearchesCommand extends Command
{
    protected $signature = 'search:recount {--shop= : one shop, by slug or ID} {--days=90 : how many days back}';

    protected $description = 'Count again the days before a search sent again from the same address counted once.';

    public function handle(TenantContext $tenant): int
    {
        $yesterday = CarbonImmutable::yesterday();
        $from = $yesterday->subDays(max(1, (int) $this->option('days')) - 1);
        $shops = Shop::query()->where('status', ShopStatus::Active)
            ->when($this->option('shop') !== null, fn ($q) => $q->where(fn ($w) => $w->where('id', (string) $this->option('shop'))->orWhere('slug', (string) $this->option('shop'))))
            ->orderBy('id')->get();

        foreach ($shops as $shop) {
            $done = (string) Settings::get('search.recounted_until', (string) $shop->id);
            $start = $done !== '' && CarbonImmutable::parse($done)->greaterThanOrEqualTo($from) ? CarbonImmutable::parse($done)->addDay() : $from;
            if ($start->greaterThan($yesterday)) {
                continue;
            }

            $lowered = $tenant->run((string) $shop->id, function () use ($start, $yesterday): int {
                $rows = SearchLog::query()
                    ->where('created_at', '>=', $start->startOfDay())->where('created_at', '<', $yesterday->addDay()->startOfDay())
                    ->whereNotNull('ip')
                    ->selectRaw('date(created_at) as day, query, count(*) as sent, count(distinct ip) as people, sum(case when results = 0 then 1 else 0 end) as sent_empty, count(distinct case when results = 0 then ip end) as people_empty')
                    ->groupByRaw('date(created_at), query')->get();
                $lowered = 0;
                foreach ($rows as $row) {
                    $again = (int) $row->sent - (int) $row->people;
                    $againEmpty = (int) $row->sent_empty - (int) $row->people_empty;
                    if ($again <= 0) {
                        continue;
                    }
                    $term = SearchTerm::query()->whereDate('day', (string) $row->day)->where('query', (string) $row->query)->first();
                    if ($term === null) {
                        continue;
                    }
                    $term->forceFill([
                        'searches' => max(1, (int) $term->searches - $again),
                        'empty' => max(0, (int) $term->empty - max(0, $againEmpty)),
                    ])->save();
                    $lowered += $again;
                }

                return $lowered;
            });

            Settings::set('search.recounted_until', $yesterday->toDateString(), (string) $shop->id);
            $this->line($shop->slug.': '.$lowered.' searches sent again, now counted once ('.$start->toDateString().' – '.$yesterday->toDateString().')');
        }

        return self::SUCCESS;
    }
}
