<?php

namespace App\Modules\Alerts\Support;

use App\Core\Facades\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * The scheduler's last sign of life, kept in the shared cache (Redis in production) so the api can read what the
 * scheduler wrote. Stale after alerts.scheduler_minutes.
 */
final class SchedulerBeat
{
    public const KEY = 'alerts:scheduler-beat';

    public function record(): void
    {
        Cache::forever(self::KEY, now()->getTimestamp());
    }

    public function last(): ?Carbon
    {
        $at = Cache::get(self::KEY);

        return is_numeric($at) ? Carbon::createFromTimestamp((int) $at) : null;
    }

    public function alive(): bool
    {
        $last = $this->last();

        return $last !== null && $last->gte(now()->subMinutes((int) Settings::get('alerts.scheduler_minutes')));
    }
}
