<?php

namespace App\Modules\Demo\Actions;

use App\Modules\Admin\Models\User;
use App\Modules\Demo\Mail\DemoMail;
use App\Modules\Demo\Models\DemoSite;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The team told, by email, when a demo needs them, so no one finds it by chance: a demo that failed (and why), and
 * a scan with nothing done for a while (a step cut off, a part held). Once a problem a demo, never again for it.
 * Every operator gets it. Asked by the sweep (SweepDemos), code only.
 */
final class AlertDemos
{
    /** A scan that did nothing this long is told of. */
    public const STALLED_MINUTES = 15;

    /** How long a problem told of is remembered, so it is told once. */
    private const REMEMBER_DAYS = 14;

    /** @return int how many alerts were sent */
    public function handle(): int
    {
        $sent = 0;

        foreach (DemoSite::query()->where('status', DemoSite::FAILED)->where('updated_at', '>', now()->subDay())->get() as $site) {
            $sent += $this->tell($site, 'failed', ['error' => (string) __('demo::page.scan.problem.'.($site->error ?? 'other').'.title')]);
        }

        foreach (DemoSite::query()->where('status', DemoSite::SCANNING)->whereNotNull('shop_id')->whereNotNull('scan_at')->get() as $site) {
            $last = self::lastActivity($site);
            if ($last !== null && $last->lt(now()->subMinutes(self::STALLED_MINUTES))) {
                $sent += $this->tell($site, 'stalled:'.$last->timestamp, ['minutes' => (string) (int) $last->diffInMinutes(now(), true), 'at' => $last->timezone('Asia/Jerusalem')->format('H:i')]);
            }
        }

        return $sent;
    }

    /** When the demo's store last did anything: its runs, started or finished; the scan's start when none yet. */
    public static function lastActivity(DemoSite $site): ?Carbon
    {
        $runs = DB::table('runs')->where('shop_id', $site->shop_id)->max('updated_at');

        return $runs !== null ? Carbon::parse($runs) : $site->scan_at;
    }

    /** @param array<string, string> $with */
    private function tell(DemoSite $site, string $problem, array $with): int
    {
        if (! Cache::add('demo:alert:'.$site->id.':'.$problem, true, now()->addDays(self::REMEMBER_DAYS))) {
            return 0;
        }

        $kind = explode(':', $problem)[0];
        $emails = User::query()->where('is_operator', true)->pluck('email')->filter()->unique()->values()->all();
        if ($emails === []) {
            return 0;
        }

        try {
            Mail::to($emails)->locale((string) config('app.locale'))->send(new DemoMail('alert', $site, [
                'what' => (string) __('demo::mail.problems.'.$kind, $with + ['site' => $site->title ?? $site->host]),
                'link' => route('filament.operator.pages.demos'),
            ]));
        } catch (Throwable $e) {
            Log::warning('demo: the team could not be told of a problem', ['demo' => $site->id, 'problem' => $problem, 'error' => $e->getMessage()]);

            return 0;
        }

        return 1;
    }
}
