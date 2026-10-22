<?php

namespace App\Modules\Leads\Console;

use App\Modules\Leads\Actions\ForgetOldLeads;
use Illuminate\Console\Command;

/** leads:forget-old — the leads older than their shop's keeping days (ForgetOldLeads). */
final class ForgetOldLeadsCommand extends Command
{
    protected $signature = 'leads:forget-old';

    protected $description = 'Delete the leads older than their shop\'s keeping days.';

    public function handle(ForgetOldLeads $forget): int
    {
        $this->info('Deleted '.$forget->handle().' leads.');

        return self::SUCCESS;
    }
}
