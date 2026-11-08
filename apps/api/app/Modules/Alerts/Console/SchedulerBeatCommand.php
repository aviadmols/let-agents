<?php

namespace App\Modules\Alerts\Console;

use App\Modules\Alerts\Support\SchedulerBeat;
use Illuminate\Console\Command;

/**
 *   alerts:beat   the scheduler says it is alive (every minute)
 *
 * Started by the scheduler as its own process, the way every scheduled command is, so a scheduler that can no
 * longer start one (as on 4.11, when the container ran out of processes and every sync failed for four days)
 * stops beating too. GET /api/v1/up/scheduler reads the beat from the api, outside the scheduler.
 */
final class SchedulerBeatCommand extends Command
{
    protected $signature = 'alerts:beat';

    protected $description = 'Record that the scheduler can still start its commands, for /api/v1/up/scheduler.';

    public function handle(SchedulerBeat $beat): int
    {
        $beat->record();

        return self::SUCCESS;
    }
}
