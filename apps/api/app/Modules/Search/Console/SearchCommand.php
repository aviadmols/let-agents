<?php

namespace App\Modules\Search\Console;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Search\Actions\ArrangeFilters;
use App\Modules\Search\Actions\BuildSearchIndex;
use App\Modules\Search\Actions\CheckStoreSearch;
use App\Modules\Search\Actions\LearnFromSearches;
use App\Modules\Search\Actions\ResolveEmptySearches;
use App\Modules\Search\Actions\SearchCatalog;
use App\Modules\Search\Actions\SettleWordFamilies;
use App\Modules\Search\Actions\WritePageTags;
use App\Modules\Search\Actions\WriteWordFamilies;
use App\Modules\Search\Models\SearchClick;
use App\Modules\Search\Models\SearchFilterLayout;
use App\Modules\Search\Models\SearchIndex;
use App\Modules\Search\Models\SearchPhotoAsk;
use App\Modules\Search\Models\SearchShare;
use App\Modules\Search\Models\SearchTerm;
use App\Modules\Search\Support\FilterLayoutGate;
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
 *   search layout gueta-avigdor           arrange the filter screen (two models, code applies only order and hiding)
 *   search layout gueta-avigdor --show    the stored arrangement; --clear removes it (the screen as code builds it)
 *   search prune                          drop counts older than search.keep_days
 */
final class SearchCommand extends Command
{
    protected $signature = 'search
        {step : index, resolve, tags, check, layout, try or prune}
        {target? : shop slug or ID}
        {query? : what to search, for try}
        {--all : every active shop}
        {--show : layout: print the stored arrangement, ask no model}
        {--clear : layout: remove the shop arrangement}
        {--force : layout: ask again though the screen is unchanged}';

    protected $description = 'Build a shop\'s search index, arrange its filters, try a query, or prune old counts.';

    public function handle(TenantContext $tenant): int
    {
        return $tenant->runUnscoped(fn (): int => match ($this->argument('step')) {
            'index' => $this->index(),
            'learn' => $this->learn(),
            'resolve' => $this->resolve(),
            'tags' => $this->tags(),
            'check' => $this->check(),
            'layout' => $this->layout(),
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
            // what the families and the searched words wait for, settled by the judge (SettleWordFamilies): its choices build the index again
            $settled = $run->status->value === 'succeeded' ? app(SettleWordFamilies::class)->handle($shop->id, $this->option('all') ? RunTrigger::Schedule : RunTrigger::Manual) : null;
            if ($settled !== null) {
                $this->line("{$shop->slug}: ".$settled->summary());
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
            // what the families and the searched words wait for, settled by the judge (SettleWordFamilies): its choices build the index again
            $settled = $run->status->value === 'succeeded' ? app(SettleWordFamilies::class)->handle($shop->id, $this->option('all') ? RunTrigger::Schedule : RunTrigger::Manual) : null;
            if ($settled !== null) {
                $this->line("{$shop->slug}: ".$settled->summary());
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
            // what the families and the searched words wait for, settled by the judge (SettleWordFamilies): its choices build the index again
            $settled = $run->status->value === 'succeeded' ? app(SettleWordFamilies::class)->handle($shop->id, $this->option('all') ? RunTrigger::Schedule : RunTrigger::Manual) : null;
            if ($settled !== null) {
                $this->line("{$shop->slug}: ".$settled->summary());
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

    /**
     * The filter screen arranged for one named shop, never for every shop and never on a schedule (ArrangeFilters): the
     * screen before and after, in Hebrew, and every change with the checker's score and reason. Applied, the index is
     * built again so the drawer gets it.
     */
    private function layout(): int
    {
        $he = fn (string $key, array $params = []): string => (string) trans('search::layout.'.$key, $params, 'he');
        if ($this->option('all')) {
            return $this->failWith($he('one_shop'));
        }
        $shop = $this->shop();
        if ($shop === null) {
            return $this->failWith('Shop not found.');
        }
        $tenant = app(TenantContext::class);

        if ($this->option('clear')) {
            $removed = (int) $tenant->run($shop->id, fn (): int => SearchFilterLayout::query()->delete());
            $this->line($he('cleared', ['count' => $removed]));
            if ($tenant->run($shop->id, fn (): bool => SearchIndex::query()->exists())) {
                app(BuildSearchIndex::class)->handle($shop->id);
                $this->line($he('reindexed'));
            }

            return self::SUCCESS;
        }

        if ($this->option('show')) {
            $stored = $tenant->run($shop->id, fn (): ?SearchFilterLayout => SearchFilterLayout::query()->orderByDesc('created_at')->orderByDesc('id')->first());
            if ($stored === null) {
                return $this->failWith($he('none'));
            }
            $this->showLayout($stored, $he);

            return self::SUCCESS;
        }

        if (! Features::enabled(ArrangeFilters::FEATURE, $shop->id)) {
            $this->line($he('off'));

            return self::SUCCESS;
        }

        ['run' => $run, 'layout' => $stored] = app(ArrangeFilters::class)->handle($shop->id, (bool) $this->option('force'));
        foreach ((array) ($run->output['steps'] ?? []) as $step) {
            $this->line((string) $step);
        }
        $this->line("{$shop->slug}: ".$run->summary());
        if ($stored !== null) {
            $this->showLayout($stored, $he);
        }
        if ($run->status->value === 'succeeded' && $stored !== null && $stored->status === SearchFilterLayout::APPLIED && empty($run->output['unchanged'])) {
            app(BuildSearchIndex::class)->handle($shop->id);
            $this->line($he('reindexed'));
        }

        return $run->status->value === 'succeeded' ? self::SUCCESS : self::FAILURE;
    }

    /** @param  callable(string, array<string, mixed>=): string  $he */
    private function showLayout(SearchFilterLayout $stored, callable $he): void
    {
        $this->info($he('stored', ['id' => $stored->id, 'status' => $he('status.'.$stored->status), 'usd' => number_format((float) $stored->cost_usd, 2, '.', '')]));
        foreach ((array) $stored->problems as $problem) {
            $this->line('  '.$problem);
        }
        if (! is_array($stored->screen)) {
            return;
        }
        $labels = array_column((array) ($stored->screen['filters'] ?? []), 'label', 'key');
        $print = function (string $title, array $screen) use ($he, $labels): void {
            $this->line('');
            $this->line($title);
            foreach ($screen['shown'] as $n => $filter) {
                $this->line($he('filter', ['n' => $n + 1, 'label' => $filter['label'], 'products' => $filter['products']]));
                $values = array_map(fn (array $v): string => $v['label'].' ('.$v['products'].')', $filter['values']);
                $this->line('     '.implode(', ', $values).($filter['more'] > 0 ? ' '.$he('more', ['count' => $filter['more']]) : ''));
            }
            foreach ($screen['hidden'] as $filter) {
                $this->line($he('hidden', ['label' => $filter['label'], 'reason' => self::layoutReason($filter['reason'], $he, $labels)]));
            }
        };
        $print($he('before'), FilterLayoutGate::screen($stored->screen));
        if ($stored->status !== SearchFilterLayout::FAILED) {
            $print($he('after'), FilterLayoutGate::screen($stored->screen, (array) $stored->applied));
        }

        $items = (array) $stored->items;
        if ($items !== []) {
            $this->line('');
            $this->line($he('changes'));
        }
        $verdicts = (array) ($stored->review['verdicts'] ?? []);
        $refused = array_column((array) ($stored->refused['refused'] ?? []), 'why', 'id');
        foreach ($items as $item) {
            $what = match ($item['kind']) {
                'order' => implode(' › ', (array) $item['labels']),
                'values' => $item['label'].': '.implode(' › ', (array) $item['labels']),
                'hide' => $item['label'].' ('.self::layoutReason((string) $item['reason'], $he, $labels).')',
                'move' => $item['label'].' › '.$item['value_label'].' → '.$item['into_label'].' › '.$item['as_label'].' ('.(int) round(100 * (float) $item['cover']).'%)',
                default => '',
            };
            $verdict = $verdicts[$item['id']] ?? null;
            $state = match (true) {
                $verdict === null => $he('state.unscored'),
                isset($refused[$item['id']]) => $he('state.refused', ['why' => $he('refusal.'.$refused[$item['id']])]),
                (bool) $verdict['applied'] => $he('state.applied'),
                default => $he('state.'.$verdict['why']),
            };
            $this->line($he('item', ['kind' => $he('kind.'.$item['kind']), 'what' => $what]));
            $this->line('      '.$he('score', ['score' => $verdict['score'] ?? '-', 'state' => $state, 'reason' => (string) ($verdict['reason'] ?? '')]));
            if ((string) ($item['why'] ?? '') !== '') {
                $this->line('      '.$he('why', ['why' => (string) $item['why']]));
            }
        }
        $ignored = (array) ($stored->refused['ignored'] ?? []);
        if ($ignored !== []) {
            $this->line('');
            $this->line($he('ignored', ['count' => count($ignored)]));
            foreach ($ignored as $entry) {
                $this->line('  '.trim($entry['filter'].' '.$entry['key']).': '.$he('ignore.'.$entry['why']));
            }
        }
        foreach ((array) ($stored->proposal['notes'] ?? []) as $note) {
            $this->line($he('note', ['note' => (string) $note]));
        }
    }

    /**
     * @param  callable(string, array<string, mixed>=): string  $he
     * @param  array<string, string>  $labels  the screen's filter labels, by key
     */
    private static function layoutReason(string $reason, callable $he, array $labels): string
    {
        if (str_starts_with($reason, 'duplicate_of:')) {
            $of = substr($reason, strlen('duplicate_of:'));

            return $he('reason.duplicate', ['of' => $labels[$of] ?? $of]);
        }

        return $he('reason.'.$reason);
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
