<?php

namespace App\Modules\Runs\Console;

use App\Core\Tenancy\TenantContext;
use App\Modules\Runs\Support\FirstScan;
use App\Modules\Tenancy\Enums\ShopStatus;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * scan:status [shop]   where each shop's scan is: each step's state, when it began and ended. Reads only.
 */
final class ScanStatusCommand extends Command
{
    protected $signature = 'scan:status {shop? : shop slug or ID}';

    protected $description = 'Show where each shop scan is, step by step. Reads only.';

    public function handle(TenantContext $tenant): int
    {
        $key = (string) $this->argument('shop');
        $shops = $tenant->runUnscoped(fn () => Shop::query()
            ->when($key !== '', fn ($q) => $q->where(fn ($q) => $q->where('slug', $key)->orWhere('id', $key)))
            ->when($key === '', fn ($q) => $q->where('status', ShopStatus::Active))
            ->get());

        foreach ($shops as $shop) {
            $state = FirstScan::state($shop);
            $this->line($shop->slug.': '.($state === null ? 'no scan this week' : 'started '.$state['started']->timezone('Asia/Jerusalem')->format('d.m H:i').($state['finished'] ? ', finished' : '')));

            foreach ($state['steps'] ?? [] as $step => $is) {
                $at = fn (string $what): string => (string) (($t = Cache::get('first-scan:'.$shop->id.':'.$what.':'.$step)) ? Carbon::parse((string) $t)->timezone('Asia/Jerusalem')->format('H:i') : '-');
                $this->line(sprintf('  %-10s %-8s began %s ended %s', $step, $is, $at('began'), $at('ended')));
            }
        }

        return self::SUCCESS;
    }
}
