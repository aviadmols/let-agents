<?php

namespace App\Modules\Catalog\Console;

use App\Modules\Catalog\Actions\SamplePages;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Console\Command;

/**
 * catalog:sample-pages rocks-and-gold     a few pages of every kind, read for what the feed does not carry
 */
final class SamplePagesCommand extends Command
{
    protected $signature = 'catalog:sample-pages {shop : shop slug or ID}';

    protected $description = 'Read a product page of every kind (category, type, choices) for what the feed does not carry: linked versions, subscriptions, page-only choices.';

    public function handle(SamplePages $sample): int
    {
        $shop = Shop::query()->where('slug', (string) $this->argument('shop'))->orWhere('id', (string) $this->argument('shop'))->first();

        if ($shop === null) {
            $this->error('Shop not found.');

            return self::FAILURE;
        }

        $run = $sample->handle($shop->id, RunTrigger::Manual);
        $this->line($run === null ? $shop->slug.': off, or the store is resting' : (string) $run->summary());

        return self::SUCCESS;
    }
}
