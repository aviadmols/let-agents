<?php

namespace App\Modules\Admin\Console;

use App\Modules\Admin\Actions\EndDuePlans;
use Illuminate\Console\Command;

/** shops:end-due-plans — the shops whose cancelled plan reached the end of the period already paid pause (EndDuePlans). */
final class EndDuePlansCommand extends Command
{
    protected $signature = 'shops:end-due-plans';

    protected $description = 'Pause the shops whose cancelled plan reached the end of the period already paid.';

    public function handle(EndDuePlans $end): int
    {
        $this->info('Paused '.$end->handle().' shops.');

        return self::SUCCESS;
    }
}
