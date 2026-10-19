<?php

namespace App\Modules\Runs\Console;

use App\Core\Tenancy\TenantContext;
use App\Modules\Runs\Support\FirstScan;
use App\Modules\Tenancy\Enums\ShopStatus;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Console\Command;

/**
 * scan:resume   every active shop's scan that stopped on the way, carried on from where it stopped
 *               (FirstScan::resume): the schedule runs it every five minutes.
 */
final class ResumeScansCommand extends Command
{
    protected $signature = 'scan:resume';

    protected $description = 'Carry on every shop scan that stopped on the way (a deploy, a worker cut off).';

    public function handle(TenantContext $tenant): int
    {
        foreach ($tenant->runUnscoped(fn () => Shop::query()->where('status', ShopStatus::Active)->get()) as $shop) {
            if (($from = FirstScan::resume($shop)) !== null) {
                $this->line("{$shop->slug}: carried on from {$from}");
            }
        }

        return self::SUCCESS;
    }
}
