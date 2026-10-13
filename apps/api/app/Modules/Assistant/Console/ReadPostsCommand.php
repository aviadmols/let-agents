<?php

namespace App\Modules\Assistant\Console;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Assistant\Actions\ReadPostKnowledge;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Tenancy\Enums\ShopStatus;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Console\Command;

/**
 *   assistant:read-posts gueta-avigdor   what the shop's new or changed posts say about the store, now
 *   assistant:read-posts --all           what the schedule runs, for shops with the flag on
 */
final class ReadPostsCommand extends Command
{
    protected $signature = 'assistant:read-posts {shop? : slug or ID} {--all : every active shop}';

    protected $description = 'Read what a shop\'s new or changed posts say about the store, into what the assistant knows.';

    public function handle(TenantContext $tenant): int
    {
        return $tenant->runUnscoped(function (): int {
            $shops = $this->option('all')
                ? Shop::query()->where('status', ShopStatus::Active)->orderBy('slug')->get()->filter(fn (Shop $s): bool => Features::enabled('assistant.knowledge', $s->id))
                : Shop::query()->where('slug', (string) $this->argument('shop'))->orWhere('id', (string) $this->argument('shop'))->get();

            if ($shops->isEmpty() && ! $this->option('all')) {
                $this->error('Shop not found.');

                return self::FAILURE;
            }

            $failed = 0;

            foreach ($shops as $shop) {
                $run = app(ReadPostKnowledge::class)->handle($shop->id, $this->option('all') ? RunTrigger::Schedule : RunTrigger::Manual);
                $this->line("{$shop->slug}: ".$run->summary());
                $failed += $run->status->value === 'succeeded' ? 0 : 1;
            }

            return $failed === 0 ? self::SUCCESS : self::FAILURE;
        });
    }
}
