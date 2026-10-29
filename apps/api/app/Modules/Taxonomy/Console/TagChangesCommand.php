<?php

namespace App\Modules\Taxonomy\Console;

use App\Core\Facades\Features;
use App\Modules\Taxonomy\Jobs\TagChangesJob;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Console\Command;

/**
 * Each night, every shop that tags by itself (taxonomy.tagging) tags each product and post whose words changed since
 * it was tagged: what the syncs between nights brought, the scan's new facts, a new post. On the queue; `--now` here.
 *
 *   taxonomy:tag-changes                 every such shop
 *   taxonomy:tag-changes gil-travel      one shop, its switch on or not
 */
final class TagChangesCommand extends Command
{
    protected $signature = 'taxonomy:tag-changes {shop? : the shop\'s slug or id} {--now : here, not on the queue}';

    protected $description = 'Tag the products and posts that changed since they were tagged, where a shop tags by itself';

    public function handle(): int
    {
        $one = $this->argument('shop');
        $shops = Shop::query()->when($one !== null, fn ($q) => $q->where('slug', $one)->orWhere('id', $one))->get();

        foreach ($shops as $shop) {
            if ($one === null && ! Features::enabled('taxonomy.tagging', $shop->id)) {
                continue;
            }
            $job = new TagChangesJob($shop->id, true);
            $this->option('now') ? app()->call([$job, 'handle']) : dispatch($job);
            $this->line($shop->name);
        }

        return self::SUCCESS;
    }
}
