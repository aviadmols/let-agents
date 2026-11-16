<?php

namespace App\Modules\Assistant\Console;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Assistant\Actions\BuildTradeGlossary;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Tenancy\Enums\ShopStatus;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Console\Command;

/**
 *   assistant:terms rocks-and-gold   the store's bank of trade terms, its new words only, now (a scan's step)
 *   assistant:terms --all            what the schedule runs, for shops with the flag on
 *   assistant:terms rocks --show     the words code would send, and nothing asked
 */
final class TermsCommand extends Command
{
    protected $signature = 'assistant:terms {shop? : slug or ID} {--all : every active shop} {--show : list the candidate words, no model} {--trial : write it while the flag is off, to try it in a quality run (assistant:quality-baseline run --terms); shoppers do not read it}';

    protected $description = 'Write a shop\'s bank of the terms of its trade from its own data: one family writes, the other checks.';

    public function handle(TenantContext $tenant): int
    {
        return $tenant->runUnscoped(function () use ($tenant): int {
            $shops = $this->option('all')
                ? Shop::query()->where('status', ShopStatus::Active)->orderBy('slug')->get()->filter(fn (Shop $s): bool => Features::enabled('assistant.trade_terms', $s->id))
                : Shop::query()->where('slug', (string) $this->argument('shop'))->orWhere('id', (string) $this->argument('shop'))->get();

            if ($shops->isEmpty() && ! $this->option('all')) {
                $this->error('Shop not found.');

                return self::FAILURE;
            }

            if ($this->option('show')) {
                foreach ($shops as $shop) {
                    $words = $tenant->run($shop->id, fn (): array => BuildTradeGlossary::candidates());
                    $this->line("{$shop->slug}: ".count($words).' words');
                    foreach ($words as $w) {
                        $this->line("  [{$w['from']}] {$w['term']} ({$w['products']})");
                    }
                }

                return self::SUCCESS;
            }

            $failed = 0;
            foreach ($shops as $shop) {
                // off for the shop: nothing run, so its scan tells nothing of a bank it does not have
                $trial = (bool) $this->option('trial') && ! $this->option('all');
                if (! $trial && ! Features::enabled('assistant.trade_terms', $shop->id)) {
                    $this->line("{$shop->slug}: off");

                    continue;
                }
                $run = app(BuildTradeGlossary::class)->handle($shop->id, $this->option('all') ? RunTrigger::Schedule : RunTrigger::Manual, $trial);
                $this->line("{$shop->slug}: ".$run->summary());
                $failed += $run->status->value === 'succeeded' ? 0 : 1;
            }

            return $failed === 0 ? self::SUCCESS : self::FAILURE;
        });
    }
}
