<?php

namespace App\Modules\Admin\Support;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Contracts\TodayFix;
use App\Modules\Admin\Contracts\TodayImprovements;
use App\Modules\Admin\Contracts\TodaySearches;
use App\Modules\Admin\Contracts\TodayWin;
use App\Modules\Tenancy\Models\Shop;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * What Today shows, gathered from the modules (the designer's redesign, 11.2026): the fixes waiting for the owner from
 * every module that brings them (TodayImprovements), ranked by impact, without those the owner put off this week;
 * the one tap that approves one; the fixes approved and what they did; and the search's part (TodaySearches). Each
 * module is asked inside the shop; one that fails is left out and reported, never the page. Nothing here knows a module.
 */
final class Today
{
    /** "Not now" puts a fix off until next week: the key carries the week. */
    public const LATER = 'today.later.';

    /** The order of the kinds when two fixes weigh the same: a quick win first. */
    private const CHIP_ORDER = [TodayFix::QUICK => 0, TodayFix::ASKED => 1, TodayFix::NICE => 2, TodayFix::BIGGER => 3];

    public function __construct(private readonly TenantContext $tenant) {}

    /** @return list<TodayFix> every fix waiting, the weightiest first */
    public function fixes(Shop $shop): array
    {
        $later = array_fill_keys(DB::table('shop_notices_done')->where('shop_id', $shop->id)->where('key', 'like', self::LATER.self::week().'.%')->pluck('key')->all(), true);
        $fixes = array_values(array_filter(
            $this->each(TodayImprovements::TAG, $shop, fn (TodayImprovements $source): array => $source->waiting($shop)),
            fn (TodayFix $fix): bool => ! isset($later[self::laterKey($fix->key)]),
        ));
        usort($fixes, fn (TodayFix $a, TodayFix $b): int => [$b->impact, self::CHIP_ORDER[$a->chip] ?? 9, $a->key] <=> [$a->impact, self::CHIP_ORDER[$b->chip] ?? 9, $b->key]);

        return $fixes;
    }

    /** @return string|null what changed, or null when no module knew the fix */
    public function approve(Shop $shop, string $key, ?int $userId): ?string
    {
        foreach (app()->tagged(TodayImprovements::TAG) as $source) {
            try {
                $said = $this->tenant->run((string) $shop->id, fn (): ?string => $source->approve($shop, $key, $userId));
            } catch (Throwable $e) {
                report($e);

                continue;
            }
            if ($said !== null) {
                return $said;
            }
        }

        return null;
    }

    /** Not now: the fix leaves Today until next week. */
    public function later(Shop $shop, string $key, ?int $userId): void
    {
        app(Home::class)->markDone($shop, self::laterKey($key), $userId);
    }

    /** @return list<TodayWin> the latest approved first */
    public function wins(Shop $shop, int $limit = 3): array
    {
        $wins = $this->each(TodayImprovements::TAG, $shop, fn (TodayImprovements $source): array => $source->wins($shop, $limit));
        usort($wins, fn (TodayWin $a, TodayWin $b): int => [$b->approvedAt->getTimestamp(), $a->key] <=> [$a->approvedAt->getTimestamp(), $b->key]);

        return array_slice($wins, 0, $limit);
    }

    /** @return array{searches: int, questions: int, found: int}|null */
    public function found(Shop $shop, CarbonInterface $since, CarbonInterface $until): ?array
    {
        return $this->search($shop, fn (TodaySearches $s): array => $s->found($shop, $since, $until));
    }

    /** @return list<array{query: string, searches: int, before: int, found: string|null, picture: string|null}> */
    public function top(Shop $shop, CarbonInterface $since, CarbonInterface $until, int $limit = 5): array
    {
        $before = $since->copy()->sub($since->diff($until));

        return $this->search($shop, fn (TodaySearches $s): array => $s->top($shop, $since, $until, $before, $limit)) ?? [];
    }

    public function topOn(Shop $shop, CarbonInterface $day): ?string
    {
        return $this->search($shop, fn (TodaySearches $s): ?string => $s->topOn($shop, $day));
    }

    /** @return list<array{title: string, picture: string|null, adds: int, phrase: string|null, photo: bool}> */
    public function sold(Shop $shop, CarbonInterface $since, CarbonInterface $until, int $limit = 5): array
    {
        return $this->search($shop, fn (TodaySearches $s): array => $s->sold($shop, $since, $until, $limit)) ?? [];
    }

    /** @return list<array<string, mixed>> */
    public function feed(Shop $shop, string $search = '', int $limit = 6): array
    {
        return $this->search($shop, fn (TodaySearches $s): array => $s->feed($shop, $search, $limit)) ?? [];
    }

    private static function laterKey(string $key): string
    {
        return self::LATER.self::week().'.'.(mb_strlen($key) > 120 ? sha1($key) : $key);
    }

    private static function week(): string
    {
        return now()->format('o-W');
    }

    /**
     * @template T
     *
     * @param  callable(TodaySearches): T  $ask
     * @return T|null
     */
    private function search(Shop $shop, callable $ask): mixed
    {
        foreach (app()->tagged(TodaySearches::TAG) as $source) {
            try {
                return $this->tenant->run((string) $shop->id, fn () => $ask($source));
            } catch (Throwable $e) {
                report($e);
            }
        }

        return null;
    }

    /**
     * @param  callable(object): list<mixed>  $ask
     * @return list<mixed>
     */
    private function each(string $tag, Shop $shop, callable $ask): array
    {
        $answers = [];
        foreach (app()->tagged($tag) as $source) {
            try {
                array_push($answers, ...$this->tenant->run((string) $shop->id, fn (): array => $ask($source)));
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $answers;
    }
}
