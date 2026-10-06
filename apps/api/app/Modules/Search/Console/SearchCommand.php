<?php

namespace App\Modules\Search\Console;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Search\Actions\BuildSearchIndex;
use App\Modules\Search\Actions\SearchCatalog;
use App\Modules\Search\Models\SearchClick;
use App\Modules\Search\Models\SearchTerm;
use App\Modules\Tenancy\Enums\ShopStatus;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Console\Command;

/**
 * The search box's index, and a way to try it from the shell.
 *
 *   search index gueta-avigdor
 *   search index --all                    what the schedule runs
 *   search try gueta-avigdor "מקיטא"      results, not counted
 *   search prune                          drop counts older than search.keep_days
 */
final class SearchCommand extends Command
{
    protected $signature = 'search
        {step : index, try or prune}
        {target? : shop slug or ID}
        {query? : what to search, for try}
        {--all : every active shop}';

    protected $description = 'Build a shop\'s search index, try a query, or prune old counts.';

    public function handle(TenantContext $tenant): int
    {
        return $tenant->runUnscoped(fn (): int => match ($this->argument('step')) {
            'index' => $this->index(),
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
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
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
        $this->line("Pruned {$terms} query rows and {$clicks} click rows before {$before}.");

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
