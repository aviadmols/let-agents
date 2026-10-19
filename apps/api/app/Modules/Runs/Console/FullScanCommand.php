<?php

namespace App\Modules\Runs\Console;

use App\Core\Tenancy\TenantContext;
use App\Modules\Runs\Support\FirstScan;
use App\Modules\Tenancy\Enums\ShopStatus;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Console\Command;

/**
 * The operator's full scan from the shell, for one shop or every active one: the whole chain
 * again (FirstScan::STEPS), on the long queue, each step reading only what changed in it.
 *
 *   scan:full rocks-and-gold
 *   scan:full --all
 */
final class FullScanCommand extends Command
{
    protected $signature = 'scan:full {shop? : shop slug or ID} {--all : every active shop}';

    protected $description = 'Scan a shop again from its catalogue to its search index, as the operator\'s full scan does.';

    public function handle(TenantContext $tenant): int
    {
        $key = (string) $this->argument('shop');

        if ($key === '' && ! $this->option('all')) {
            $this->error('A shop, or --all.');

            return self::INVALID;
        }

        $shops = $tenant->runUnscoped(fn () => Shop::query()
            ->when($key !== '', fn ($q) => $q->where(fn ($q) => $q->where('slug', $key)->orWhere('id', $key)))
            ->when($key === '', fn ($q) => $q->where('status', ShopStatus::Active))
            ->get());

        foreach ($shops as $shop) {
            FirstScan::start($shop, full: true);
            $this->line($shop->slug);
        }

        return self::SUCCESS;
    }
}
