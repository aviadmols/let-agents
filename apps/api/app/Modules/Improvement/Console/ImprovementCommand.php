<?php

namespace App\Modules\Improvement\Console;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Improvement\Actions\RunDailyReview;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Tenancy\Enums\ShopStatus;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Console\Command;

/**
 * The daily review.
 *
 *   improvement review gueta-avigdor
 *   improvement review --all          what the schedule runs, for shops with the review on
 */
final class ImprovementCommand extends Command
{
    protected $signature = 'improvement
        {step : review}
        {target? : shop slug or ID}
        {--all : every active shop with the daily review on}';

    protected $description = 'Review what a shop\'s site did not answer, and propose changes.';

    public function handle(TenantContext $tenant): int
    {
        if ($this->argument('step') !== 'review') {
            $this->error('Unknown step.');

            return self::FAILURE;
        }

        return $tenant->runUnscoped(function (): int {
            $shops = $this->option('all')
                ? Shop::query()->where('status', ShopStatus::Active)->orderBy('slug')->get()->filter(fn (Shop $shop): bool => Features::enabled('improvement.daily_review', $shop->id))
                : collect([Shop::query()->where('slug', (string) $this->argument('target'))->orWhere('id', (string) $this->argument('target'))->first()])->filter();

            if ($shops->isEmpty() && ! $this->option('all')) {
                $this->error('Shop not found.');

                return self::FAILURE;
            }

            $failed = 0;

            foreach ($shops as $shop) {
                $run = app(RunDailyReview::class)->handle($shop->id, $this->option('all') ? RunTrigger::Schedule : RunTrigger::Manual);
                $this->line("{$shop->slug}: ".$run->summary());
                $failed += $run->status->value === 'succeeded' ? 0 : 1;
            }

            return $failed === 0 ? self::SUCCESS : self::FAILURE;
        });
    }
}
