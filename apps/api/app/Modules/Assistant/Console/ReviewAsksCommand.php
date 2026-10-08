<?php

namespace App\Modules\Assistant\Console;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Assistant\Actions\ReviewSearchAsks;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Tenancy\Enums\ShopStatus;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Console\Command;

/**
 *   assistant:review-asks gueta-avigdor            yesterday's questions in the search box
 *   assistant:review-asks gueta-avigdor 2026-10-07  a given day
 *   assistant:review-asks --all                     what the schedule runs, for shops with the flag on
 */
final class ReviewAsksCommand extends Command
{
    protected $signature = 'assistant:review-asks {shop? : slug or ID} {day? : YYYY-MM-DD, yesterday by default} {--all : every active shop}';

    protected $description = 'Score yesterday\'s questions in the search box and write the shop\'s daily report.';

    public function handle(TenantContext $tenant): int
    {
        return $tenant->runUnscoped(function (): int {
            $shops = $this->option('all')
                ? Shop::query()->where('status', ShopStatus::Active)->orderBy('slug')->get()->filter(fn (Shop $s): bool => Features::enabled('assistant.ask_review', $s->id))
                : Shop::query()->where('slug', (string) $this->argument('shop'))->orWhere('id', (string) $this->argument('shop'))->get();

            if ($shops->isEmpty() && ! $this->option('all')) {
                $this->error('Shop not found.');

                return self::FAILURE;
            }

            $failed = 0;

            foreach ($shops as $shop) {
                $run = app(ReviewSearchAsks::class)->handle($shop->id, $this->argument('day') ? (string) $this->argument('day') : null, $this->option('all') ? RunTrigger::Schedule : RunTrigger::Manual);
                $this->line("{$shop->slug}: ".$run->summary());
                $failed += $run->status->value === 'succeeded' ? 0 : 1;
            }

            return $failed === 0 ? self::SUCCESS : self::FAILURE;
        });
    }
}
