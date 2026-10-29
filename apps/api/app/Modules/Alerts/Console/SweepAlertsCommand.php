<?php

namespace App\Modules\Alerts\Console;

use App\Modules\Alerts\Actions\FindProblems;
use App\Modules\Alerts\Actions\TellProblems;
use Illuminate\Console\Command;

/**
 *   alerts:sweep          find what is wrong in the system and email the team what is news (every five minutes)
 *   alerts:sweep --dry    only print what is wrong now, told before or not; nothing is sent or remembered
 */
final class SweepAlertsCommand extends Command
{
    protected $signature = 'alerts:sweep {--dry : Print the problems found, send nothing}';

    protected $description = 'Find system problems (budget, failures, stopped work, stale syncs) and email the team the new ones.';

    public function handle(FindProblems $find, TellProblems $tell): int
    {
        $problems = $find->handle();

        if ($this->option('dry')) {
            foreach ($problems as $problem) {
                $this->line(($problem->urgent ? '! ' : '- ').$problem->title.' ['.$problem->key.']');
                $this->line('    '.$problem->what);
                $this->line('    '.$problem->next);
            }
            $this->line(count($problems).' found');

            return self::SUCCESS;
        }

        $told = $tell->handle($problems);
        $this->line(count($problems)." found, {$told} told");

        return self::SUCCESS;
    }
}
