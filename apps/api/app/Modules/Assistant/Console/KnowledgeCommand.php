<?php

namespace App\Modules\Assistant\Console;

use App\Core\Tenancy\TenantContext;
use App\Modules\Assistant\Actions\BuildShopKnowledge;
use App\Modules\Tenancy\Enums\ShopStatus;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Console\Command;

/**
 *   assistant:knowledge rocks-and-gold   read what the shop's product pages repeat, now
 *   assistant:knowledge --all            every active shop
 */
final class KnowledgeCommand extends Command
{
    protected $signature = 'assistant:knowledge {shop? : slug or ID} {--all : every active shop}';

    protected $description = 'Read what a shop\'s product pages repeat, and its team\'s answers, into what the assistant knows about the store.';

    public function handle(TenantContext $tenant, BuildShopKnowledge $knowledge): int
    {
        $shops = $tenant->runUnscoped(fn () => $this->option('all')
            ? Shop::query()->where('status', ShopStatus::Active)->orderBy('slug')->get()
            : Shop::query()->where('slug', (string) $this->argument('shop'))->orWhere('id', (string) $this->argument('shop'))->get());

        if ($shops->isEmpty()) {
            $this->error('Shop not found.');

            return self::FAILURE;
        }

        foreach ($shops as $shop) {
            $read = $tenant->run($shop->id, function () use ($knowledge, $shop): array {
                $read = $knowledge->repeated($shop->id);
                $knowledge->team();

                return $read;
            });
            $this->line("{$shop->slug}: {$read['sentences']} sentences from {$read['pages']} pages");
        }

        return self::SUCCESS;
    }
}
