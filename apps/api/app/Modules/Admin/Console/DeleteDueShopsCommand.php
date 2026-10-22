<?php

namespace App\Modules\Admin\Console;

use App\Modules\Admin\Actions\DeleteDueShops;
use Illuminate\Console\Command;

/** shops:delete-due — the shops whose owners asked for them to be deleted, on their day (DeleteDueShops). */
final class DeleteDueShopsCommand extends Command
{
    protected $signature = 'shops:delete-due';

    protected $description = 'Delete the shops whose owners asked for it, once their day came.';

    public function handle(DeleteDueShops $delete): int
    {
        $this->info('Deleted '.$delete->handle().' shops.');

        return self::SUCCESS;
    }
}
