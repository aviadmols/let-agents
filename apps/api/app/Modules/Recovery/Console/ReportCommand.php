<?php

namespace App\Modules\Recovery\Console;

use App\Core\Tenancy\TenantContext;
use App\Modules\Recovery\Actions\ReportAbandonedCarts;
use App\Modules\Recovery\Models\RecoveryCart;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Console\Command;

final class ReportCommand extends Command
{
    protected $signature = 'recovery:report {shop? : slug or ID} {--all : every shop with carts waiting}';

    protected $description = 'Reports on carts left with an email and not paid within the shop\'s wait';

    public function handle(TenantContext $tenant): int
    {
        return $tenant->runUnscoped(function (): int {
            $shops = $this->option('all')
                // Only shops with a cart waiting: a quiet hour asks nothing.
                ? Shop::query()->whereIn('id', RecoveryCart::query()->where('status', RecoveryCart::OPEN)->select('shop_id'))->get()
                : Shop::query()->where('slug', $this->argument('shop'))->orWhere('id', $this->argument('shop'))->get();

            if ($shops->isEmpty() && ! $this->option('all')) {
                $this->error('No such shop.');

                return self::FAILURE;
            }

            foreach ($shops as $shop) {
                $run = app(ReportAbandonedCarts::class)->handle($shop->id, $this->option('all') ? RunTrigger::Schedule : RunTrigger::Manual);
                $this->line($shop->slug.': '.($run->summary() ?? $run->status->value));
            }

            return self::SUCCESS;
        });
    }
}
