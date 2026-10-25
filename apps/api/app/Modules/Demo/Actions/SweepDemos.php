<?php

namespace App\Modules\Demo\Actions;

use App\Modules\Demo\Jobs\PrepareDemoJob;
use App\Modules\Demo\Models\DemoSite;
use App\Modules\Tenancy\Enums\ShopStatus;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Support\Facades\DB;

/**
 * The demos, kept in order: a preparation that stopped before the scan began (its job cut off) is sent again, a few
 * times, then the demo fails, said on its screen; a picture still not taken long after is given up on; a scan done
 * is finished for whoever left its screen (FinishDemo); a demo past its end is deleted with all its store holds
 * (the shop and, by cascade, its catalogue, search, vectors, answers, usage), its pictures dropped and its row kept
 * as expired for the team; a code never used is forgotten after a day; an expired row after demo rows' own time.
 * Code only.
 */
final class SweepDemos
{
    /** An expired demo's row, without its store, stays this long for the team to see who asked. */
    private const ROW_DAYS = 90;

    /** A demo's preparation is sent this many times at most. */
    private const TRIES = 3;

    public function __construct(private readonly FinishDemo $finish) {}

    /** A preparation with no step done this long is taken to have stopped. */
    private const STOPPED_MINUTES = 4;

    /** @return array{picked_up: int, finished: int, expired: int, forgotten: int} */
    public function handle(): array
    {
        $counts = ['picked_up' => 0, 'finished' => 0, 'expired' => 0, 'forgotten' => 0];

        foreach (DemoSite::query()->where('status', DemoSite::SCANNING)->whereNotNull('shop_id')->whereNull('scan_at')
            ->where('updated_at', '<', now()->subMinutes(self::STOPPED_MINUTES))->get() as $site) {
            if ($site->attempts >= self::TRIES) {
                $site->forceFill(['status' => DemoSite::FAILED, 'error' => 'stuck'])->save();

                continue;
            }
            $site->touch();
            PrepareDemoJob::dispatch($site->id)->onQueue('long');
            $counts['picked_up']++;
        }

        DemoSite::query()->where('status', DemoSite::SCANNING)->whereNotNull('scan_at')->whereNull('shot_at')
            ->where('scan_at', '<', now()->subMinutes(15))->update(['shot_at' => now()]);

        foreach (DemoSite::query()->where('status', DemoSite::SCANNING)->get() as $site) {
            $counts['finished'] += $this->finish->handle($site)->status !== DemoSite::SCANNING ? 1 : 0;
        }

        foreach (DemoSite::query()->whereIn('status', [DemoSite::READY, DemoSite::SCANNING, DemoSite::FAILED])->where(fn ($q) => $q->where('expires_at', '<', now())
            ->orWhere(fn ($q) => $q->where('status', DemoSite::FAILED)->where('updated_at', '<', now()->subDay())))->get() as $site) {
            self::end($site);
            $counts['expired']++;
        }

        $counts['forgotten'] += DemoSite::query()->where('status', DemoSite::PENDING)->where('created_at', '<', now()->subDay())->delete();
        $counts['forgotten'] += DemoSite::query()->where('status', DemoSite::EXPIRED)->where('updated_at', '<', now()->subDays(self::ROW_DAYS))->delete();

        return $counts;
    }

    /** What a demo store's model calls cost so far (the spend ledger, ai_usage). */
    public static function cost(string $shopId): float
    {
        return round((float) DB::table('ai_usage')->where('shop_id', $shopId)->sum('cost_usd'), 4);
    }

    /** A demo ended now: its store deleted when no other live demo shows it, its pictures dropped. */
    public static function end(DemoSite $site): void
    {
        $shopId = $site->shop_id;
        $site->forceFill(['status' => DemoSite::EXPIRED, 'screenshot' => null, 'screenshot_mobile' => null, 'expires_at' => $site->expires_at ?? now(),
            'cost_usd' => $shopId === null ? $site->cost_usd : self::cost($shopId)])->save();

        $shown = $shopId !== null && DemoSite::query()->where('shop_id', $shopId)->whereIn('status', [DemoSite::SCANNING, DemoSite::READY])->exists();
        if ($shopId !== null && ! $shown) {
            Shop::query()->whereKey($shopId)->where('status', ShopStatus::Demo->value)->first()?->delete();
        }
    }
}
