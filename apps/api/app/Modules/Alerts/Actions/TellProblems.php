<?php

namespace App\Modules\Alerts\Actions;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Modules\Admin\Models\User;
use App\Modules\Alerts\Mail\SystemAlertMail;
use App\Modules\Alerts\Support\Problem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The team told, in one email, of the problems that are news: a problem told of is not told again for
 * alerts.repeat_hours (or until its own end, a month's budget). Every operator gets it. An email that could not be
 * sent forgets what it held, so the next sweep tries again.
 */
final class TellProblems
{
    public const TOLD = 'alerts:told:';

    /**
     * @param  list<Problem>  $problems
     * @return int how many problems were told of
     */
    public function handle(array $problems): int
    {
        if ($problems === [] || ! Features::enabled('alerts.email')) {
            return 0;
        }

        $emails = User::query()->where('is_operator', true)->pluck('email')->filter()->unique()->values()->all();
        if ($emails === []) {
            return 0;
        }

        $repeat = now()->addHours((int) Settings::get('alerts.repeat_hours'));
        $news = array_values(array_filter($problems, fn (Problem $problem): bool => Cache::add(self::TOLD.$problem->key, true, $problem->until ?? $repeat)));
        if ($news === []) {
            return 0;
        }
        usort($news, fn (Problem $a, Problem $b): int => $b->urgent <=> $a->urgent);

        try {
            Mail::to($emails)->locale((string) config('app.locale'))->send(new SystemAlertMail($news));
        } catch (Throwable $e) {
            foreach ($news as $problem) {
                Cache::forget(self::TOLD.$problem->key);
            }
            Log::warning('alerts: the team could not be told of system problems', ['problems' => array_map(fn (Problem $p): string => $p->key, $news), 'error' => $e->getMessage()]);

            return 0;
        }

        return count($news);
    }
}
