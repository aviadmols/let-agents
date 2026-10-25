<?php

namespace App\Modules\Demo\Console;

use App\Modules\Demo\Actions\SweepDemos;
use Illuminate\Console\Command;

/**
 *   demo:sweep   finish the demos whose scan is done, end those past their time (their stores deleted), forget old rows
 */
final class SweepDemosCommand extends Command
{
    protected $signature = 'demo:sweep';

    protected $description = 'Finish ready demos, delete ended ones with all their store holds, and forget old requests.';

    public function handle(SweepDemos $sweep): int
    {
        $counts = $sweep->handle();
        $this->line("finished {$counts['finished']}, ended {$counts['expired']}, forgotten {$counts['forgotten']}");

        return self::SUCCESS;
    }
}
