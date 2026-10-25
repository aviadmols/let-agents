<?php

namespace App\Modules\Demo\Console;

use App\Modules\Demo\Actions\AlertDemos;
use App\Modules\Demo\Actions\SweepDemos;
use Illuminate\Console\Command;

/**
 *   demo:sweep   finish the demos whose scan is done, end those past their time (their stores deleted), forget old rows,
 *                and tell the team of a demo that failed or a scan that stopped (AlertDemos)
 */
final class SweepDemosCommand extends Command
{
    protected $signature = 'demo:sweep';

    protected $description = 'Finish ready demos, delete ended ones with all their store holds, forget old requests, and tell the team of problems.';

    public function handle(SweepDemos $sweep, AlertDemos $alerts): int
    {
        $counts = $sweep->handle();
        $told = $alerts->handle();
        $this->line("picked up {$counts['picked_up']}, finished {$counts['finished']}, ended {$counts['expired']}, forgotten {$counts['forgotten']}, told {$told}");

        return self::SUCCESS;
    }
}
