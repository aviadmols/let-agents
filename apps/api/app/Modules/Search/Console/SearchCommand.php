<?php

namespace App\Modules\Search\Console;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Search\Actions\BuildSearchIndex;
use App\Modules\Search\Actions\CheckStoreSearch;
use App\Modules\Search\Actions\LearnFromSearches;
use App\Modules\Search\Actions\ResolveEmptySearches;
use App\Modules\Search\Actions\SearchCatalog;
use App\Modules\Search\Actions\WritePageTags;
use App\Modules\Search\Actions\WriteWordFamilies;
use App\Modules\Search\Models\SearchClick;
use App\Modules\Search\Models\SearchPhotoAsk;
use App\Modules\Search\Models\SearchShare;
use App\Modules\Search\Models\SearchTerm;
use App\Modules\Search\Support\StoredPageTags;
use App\Modules\Tenancy\Enums\ShopStatus;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Console\Command;

/**
 * The search box's index, and a way to try it from the shell.
 *
 *   search index gueta-avigdor
 *   search index --all                    what the schedule runs
 *   search try gueta-avigdor "מקיטא"      results, not counted
 *   search resolve gueta-avigdor          answer searches that found nothing, with two models
 *   search resolve --all                  what the schedule runs, for shops with the flag on
 *   search tags gueta-avigdor             write and check the tags the pages offer
 *   search tags --all                     what the schedule runs, for shops with the flag on
 *   search check gueta-avigdor            try the search as shoppers will, mend what fails, report (a scan's last step)
 *   search prune                          drop counts older than search.keep_days
 */
final class SearchCommand extends Command
{
    protected $signature = 'search
        {step : index, resolve, tags, check, try or prune}
        {target? : shop slug or ID}
        {query? : what to search, for try}
        {--all : every active shop}';

    protected $description = 'Build a shop\'s search index, try a query, or prune old counts.';

    public function handle(TenantContext $tenant): int
    {
        return $tenant->runUnscoped(fn (): int => match ($this->argument('step')) {
            'index' => $this->index(),
            'learn' => $this->learn(),
            'resolve' => $this->resolve(),
            'tags' => $this->tags(),
            'check' => $this->check(),
            'try' => $this->try(),
            'prune' => $this->prune(),
            default => $this->failWith('Unknown step.'),
        });
    }

    private function index(): int
    {
        $shops = $this->option('all')
            ? Shop::query()->where('status', ShopStatus::Active)->orderBy('slug')->get()
            : collect([$this->shop()])->filter();

        if ($shops->isEmpty()) {
            return $this->failWith('Shop not found.');
        }

        $failed = 0;

        foreach ($shops as $shop) {
            $run = app(BuildSearchIndex::class)->handle($shop->id, $this->option('all') ? RunTrigger::Schedule : RunTrigger::Manual);
            $this->line("{$shop->slug}: ".$run->summary());
            $failed += $run->status->value === 'succeeded' ? 0 : 1;
            // its word families, once its words change (WriteWordFamilies): written, the index is built again to read them
            $families = $run->status->value === 'succeeded' ? app(WriteWordFamilies::class)->handle($shop->id, $this->option('all') ? RunTrigger::Schedule : RunTrigger::Manual) : null;
            if ($families !== null) {
                $this->line("{$shop->slug}: ".$families->summary());
                if ($families->status->value === 'succeeded') {
                    app(BuildSearchIndex::class)->handle($shop->id, $this->option('all') ? RunTrigger::Schedule : RunTrigger::Manual);
                }
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function learn(): int
    {
        $shops = $this->option('all')
            ? Shop::query()->whereIn('status', [ShopStatus::Active, ShopStatus::Demo])->orderBy('slug')->get()
            : collect([$this->shop()])->filter();

        if ($shops->isEmpty() && ! $this->option('all')) {
            return $this->failWith('Shop not found.');
        }

        foreach ($shops as $shop) {
            $run = app(LearnFromSearches::class)->handle($shop->id, $this->option('all') ? RunTrigger::Schedule : RunTrigger::Manual);
            $this->line("{$shop->slug}: ".($run?->summary() ?? 'off'));
        }

        return self::SUCCESS;
    }

    private function resolve(): int
    {
        $shops = $this->option('all')
            ? Shop::query()->where('status', ShopStatus::Active)->orderBy('slug')->get()->filter(fn (Shop $shop): bool => Features::enabled('search.resolve_empty', $shop->id))
            : collect([$this->shop()])->filter();

        if ($shops->isEmpty() && ! $this->option('all')) {
            return $this->failWith('Shop not found.');
        }

        $failed = 0;

        foreach ($shops as $shop) {
            $run = app(ResolveEmptySearches::class)->handle($shop->id, $this->option('all') ? RunTrigger::Schedule : RunTrigger::Manual);
            $this->line("{$shop->slug}: ".$run->summary());
            $failed += $run->status->value === 'succeeded' ? 0 : 1;
            // its word families, once its words change (WriteWordFamilies): written, the index is built again to read them
            $families = $run->status->value === 'succeeded' ? app(WriteWordFamilies::class)->handle($shop->id, $this->option('all') ? RunTrigger::Schedule : RunTrigger::Manual) : null;
            if ($families !== null) {
                $this->line("{$shop->slug}: ".$families->summary());
                if ($families->status->value === 'succeeded') {
                    app(BuildSearchIndex::class)->handle($shop->id, $this->option('all') ? RunTrigger::Schedule : RunTrigger::Manual);
                }
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function tags(): int
    {
        $shops = $this->option('all')
            ? Shop::query()->where('status', ShopStatus::Active)->orderBy('slug')->get()->filter(fn (Shop $shop): bool => StoredPageTags::wanted($shop->id))
            : collect([$this->shop()])->filter();

        if ($shops->isEmpty() && ! $this->option('all')) {
            return $this->failWith('Shop not found.');
        }

        $failed = 0;

        foreach ($shops as $shop) {
            $run = app(WritePageTags::class)->handle($shop->id, $this->option('all') ? RunTrigger::Schedule : RunTrigger::Manual);
            $this->line("{$shop->slug}: ".$run->summary());
            $failed += $run->status->value === 'succeeded' ? 0 : 1;
            // its word families, once its words change (WriteWordFamilies): written, the index is built again to read them
            $families = $run->status->value === 'succeeded' ? app(WriteWordFamilies::class)->handle($shop->id, $this->option('all') ? RunTrigger::Schedule : RunTrigger::Manual) : null;
            if ($families !== null) {
                $this->line("{$shop->slug}: ".$families->summary());
                if ($families->status->value === 'succeeded') {
                    app(BuildSearchIndex::class)->handle($shop->id, $this->option('all') ? RunTrigger::Schedule : RunTrigger::Manual);
                }
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function check(): int
    {
        $shop = $this->shop();

        if ($shop === null) {
            return $this->failWith('Shop not found.');
        }

        $run = app(CheckStoreSearch::class)->handle($shop->id);
        $this->line("{$shop->slug}: ".$run->summary());

        return $run->status->value === 'succeeded' ? self::SUCCESS : self::FAILURE;
    }

    private function try(): int
    {
        $shop = $this->shop();

        if ($shop === null) {
            return $this->failWith('Shop not found.');
        }

        $result = app(SearchCatalog::class)->handle($shop->id, (string) $this->argument('query'), count: false, perGroup: 8);
        $this->line("\"{$result['query']}\": {$result['total']} results".($result['semantic'] ? ', with meaning' : ', spelling only'));

        foreach ($result['groups'] as $type => $rows) {
            foreach ($rows as $row) {
                $this->line("  {$type}  {$row['title']}");
            }
        }

        return self::SUCCESS;
    }

    private function prune(): int
    {
        $before = now()->subDays((int) Settings::get('search.keep_days'))->toDateString();
        $terms = SearchTerm::query()->where('day', '<', $before)->delete();
        $clicks = SearchClick::query()->where('day', '<', $before)->delete();
        // A photo search stays, for learning what shoppers look for: only its full photo goes after its
        // days (the small thumbnail, what was seen, the tags and the results stay). The marked ones keep
        // the photo too, as the check set every change to photo search is measured against.
        $photos = SearchPhotoAsk::query()->whereNull('verdict')->whereNotNull('photo')->where('created_at', '<', now()->subDays((int) Settings::get('search.photo_keep_days')))->update(['photo' => null]);
        // A search handed to a shopper's AI: gone a day after its link expires.
        $shares = SearchShare::query()->withoutGlobalScopes()->where('expires_at', '<', now()->subDay())->delete();
        $this->line("Pruned {$terms} query rows and {$clicks} click rows before {$before}; let go of {$photos} full photos and {$shares} shared searches.");

        return self::SUCCESS;
    }

    private function shop(): ?Shop
    {
        $key = (string) $this->argument('target');

        return Shop::query()->where('slug', $key)->orWhere('id', $key)->first();
    }

    private function failWith(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
