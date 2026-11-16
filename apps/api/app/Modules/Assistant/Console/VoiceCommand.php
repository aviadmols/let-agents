<?php

namespace App\Modules\Assistant\Console;

use App\Core\Tenancy\TenantContext;
use App\Modules\Assistant\Actions\ReadShopVoice;
use App\Modules\Tenancy\Enums\ShopStatus;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Console\Command;

/**
 *   assistant:voice rocks-and-gold   read the store's tone of voice into its assistant's tone, now (a scan's step)
 *   assistant:voice --all            every active shop
 */
final class VoiceCommand extends Command
{
    protected $signature = 'assistant:voice {shop? : slug or ID} {--all : every active shop}';

    protected $description = 'Read how a shop writes to its customers into the tone its assistant answers in, unless the shop chose one.';

    public function handle(TenantContext $tenant, ReadShopVoice $voice): int
    {
        $shops = $tenant->runUnscoped(fn () => $this->option('all')
            ? Shop::query()->where('status', ShopStatus::Active)->orderBy('slug')->get()
            : Shop::query()->where('slug', (string) $this->argument('shop'))->orWhere('id', (string) $this->argument('shop'))->get());

        if ($shops->isEmpty()) {
            $this->error('Shop not found.');

            return self::FAILURE;
        }

        foreach ($shops as $shop) {
            $read = $tenant->run($shop->id, fn (): array => $voice->handle($shop->id));
            $this->line("{$shop->slug}: {$read['status']}".(isset($read['style']) ? " ({$read['style']})" : ''));
        }

        return self::SUCCESS;
    }
}
