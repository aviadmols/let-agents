<?php

namespace App\Modules\Assistant\Console;

use App\Core\Tenancy\TenantContext;
use App\Modules\Assistant\Actions\MendShownQuestions;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Tenancy\Enums\ShopStatus;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Console\Command;

/**
 *   assistant:mend-questions sellameir   the shop's shown questions not yet read, their spelling mended, now
 *   assistant:mend-questions --all       what the schedule runs, before the search index is built
 */
final class MendQuestionsCommand extends Command
{
    protected $signature = 'assistant:mend-questions {shop? : slug or ID} {--all : every active shop}';

    protected $description = 'Mend the spelling of the questions other shoppers are shown, each read once.';

    public function handle(TenantContext $tenant): int
    {
        return $tenant->runUnscoped(function (): int {
            $shops = $this->option('all')
                ? Shop::query()->where('status', ShopStatus::Active)->orderBy('slug')->get()
                : Shop::query()->where('slug', (string) $this->argument('shop'))->orWhere('id', (string) $this->argument('shop'))->get();

            if ($shops->isEmpty() && ! $this->option('all')) {
                $this->error('Shop not found.');

                return self::FAILURE;
            }

            $failed = 0;

            foreach ($shops as $shop) {
                $run = app(MendShownQuestions::class)->handle($shop->id, $this->option('all') ? RunTrigger::Schedule : RunTrigger::Manual);
                $this->line("{$shop->slug}: ".($run?->summary() ?? 'nothing to read'));
                $failed += $run === null || $run->status->value === 'succeeded' ? 0 : 1;
            }

            return $failed === 0 ? self::SUCCESS : self::FAILURE;
        });
    }
}
