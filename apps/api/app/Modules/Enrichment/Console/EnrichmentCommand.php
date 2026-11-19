<?php

namespace App\Modules\Enrichment\Console;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Actions\ActivateExpertBank;
use App\Modules\Enrichment\Actions\AuditContentReading;
use App\Modules\Enrichment\Actions\BuildCards;
use App\Modules\Enrichment\Actions\BuildExpertBank;
use App\Modules\Enrichment\Actions\BuildStructure;
use App\Modules\Enrichment\Actions\CollectProviderBatches;
use App\Modules\Enrichment\Actions\CompleteVocabulary;
use App\Modules\Enrichment\Actions\ComputeProductRelations;
use App\Modules\Enrichment\Actions\ComputeRankings;
use App\Modules\Enrichment\Actions\CreateTaskFile;
use App\Modules\Enrichment\Actions\DiscoverSchema;
use App\Modules\Enrichment\Actions\ImportRelationRules;
use App\Modules\Enrichment\Actions\ImportTaskResults;
use App\Modules\Enrichment\Actions\ImportVocabulary;
use App\Modules\Enrichment\Actions\ProposeSchema;
use App\Modules\Enrichment\Actions\ReadContentInCode;
use App\Modules\Enrichment\Actions\ReadProductsInCode;
use App\Modules\Enrichment\Actions\ReadPromisesInCode;
use App\Modules\Enrichment\Actions\RunNightlyReading;
use App\Modules\Enrichment\Enums\TaskType;
use App\Modules\Enrichment\Models\EnrichmentBatch;
use App\Modules\Enrichment\Models\EnrichmentExpertBank;
use App\Modules\Enrichment\Models\EnrichmentSchemaProposal;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Enrichment\Scanning\BranchPlan;
use App\Modules\Enrichment\Scanning\ExpertBankGate;
use App\Modules\Enrichment\Scanning\WordAudit;
use App\Modules\Enrichment\Support\TaskFile;
use App\Modules\Enrichment\Support\VocabularyBranch;
use App\Modules\Enrichment\Vocabulary\VocabularyDefinition;
use App\Modules\Runs\Models\Run;
use App\Modules\Tenancy\Enums\ShopStatus;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Console\Command;

/**
 * The same actions the operator panel runs, for scripts and local work:
 *
 *   enrichment vocabulary gueta-avigdor --template=power-tools
 *   enrichment schema gueta-avigdor --branch=branch_1751
 *   enrichment code gueta-avigdor
 *   enrichment tasks gueta-avigdor product_extraction --vocabulary=wood --limit=20 --out=tasks.jsonl
 *   enrichment results <batch id> answers.jsonl --model=claude-haiku-4-5
 *   enrichment rankings gueta-avigdor
 *   enrichment rules gueta-avigdor --template=hardware-store
 *   enrichment relations gueta-avigdor
 *   enrichment expert-bank gueta-avigdor            (a dry run: writes a report, activates nothing)
 *   enrichment expert-bank gueta-avigdor --show     (the last report, as the filters a shopper would get)
 *   enrichment expert-bank gueta-avigdor <id> --activate   (a bank that passed becomes the store's vocabulary)
 *   enrichment expert-bank gueta-avigdor --deactivate      (and back: the vocabularies it replaced return)
 */
final class EnrichmentCommand extends Command
{
    protected $signature = 'enrichment
        {step : nightly, complete, collect, vocabulary, schema, structure, expert-bank, code, cards, words, content, audit, promises, tasks, results, rankings, rules or relations}
        {target? : shop slug, or batch ID for results; none with --scheduled}
        {argument? : task type for tasks, results file for results, the id of a stored report for expert-bank --show or --activate}
        {--template= : vocabulary template name}
        {--file= : vocabulary JSON file}
        {--author= : who wrote the vocabulary}
        {--vocabulary= : vocabulary key, for tasks}
        {--tier=1 : review tier}
        {--subject=product : product or content, for reviews}
        {--limit= : maximum requests}
        {--out= : where to write the task file}
        {--model= : the model that produced the results}
        {--activate : complete: make the completed bank the active one once its checks passed (without it, a report only); expert-bank: make the stored bank named by its id the vocabulary of the shop}
        {--deactivate : expert-bank: undo the activated bank and restore the vocabularies it replaced}
        {--activate-passed : expert-bank: a bank its checks passed becomes the vocabulary at once and the products are read by it in code (a demo store scan)}
        {--force : structure: also when a vocabulary already covers the whole catalog; complete: also when the shop has it off; expert-bank: ask again though the same facts were answered}
        {--show : expert-bank: print a stored report as the filters a shopper would get, asking no model}
        {--branch= : schema: one branch of the catalog by its key (BranchPlan), also one that already has a vocabulary}
        {--include-done : ask again about products already read with the same input}
        {--all-products : nightly: every product a model has not read, not only a night of them (the first scan)}
        {--scheduled : every shop that has articles, when its flag is on (audit only)}';

    protected $description = 'Run enrichment steps from the command line.';

    public function handle(TenantContext $tenant): int
    {
        return $tenant->runUnscoped(fn (): int => match ($this->argument('step')) {
            'vocabulary' => $this->vocabulary(),
            'tasks' => $this->tasks(),
            'results' => $this->results(),
            'nightly' => $this->shopStep(fn (Shop $shop): Run => app(RunNightlyReading::class)->handle($shop->id, (bool) $this->option('all-products')), 'enrichment.nightly'),
            // The answers of batches a provider has: read every so often, for every shop whose reading goes in batches.
            'collect' => $this->collect(),
            'rankings' => $this->rankings(),
            'code' => $this->shopStep(fn (Shop $shop): Run => app(ReadProductsInCode::class)->handle($shop->id)),
            // The cards every model reads, for the whole shop at once (a sync and the night keep them since).
            'cards' => $this->shopStep(fn (Shop $shop): Run => app(BuildCards::class)->handle($shop->id)),
            // Read only: where the vocabulary's words would mislead code (WordAudit).
            'words' => $this->words(),
            // The shop's own vocabulary: counted in code, then written and reviewed, for the operator to approve.
            // Never scored or gated here: the proposal waits on the Schema discovery page for a person.
            'schema' => $this->schema(),
            // The first scan's structure: branches, a vocabulary for each, a score, and the gate.
            'structure' => $this->shopStep(fn (Shop $shop): Run => app(BuildStructure::class)->handle($shop->id, (bool) $this->option('force'))),
            // An experiment: a bank written for this store alone, measured and checked, kept as a report. Activates nothing.
            'expert-bank' => $this->expertBank(),
            'audit' => $this->shopStep(fn (Shop $shop): Run => app(AuditContentReading::class)->handle($shop->id), 'enrichment.weekly_audit'),
            'content' => $this->shopStep(fn (Shop $shop): Run => app(ReadContentInCode::class)->handle($shop->id)),
            'promises' => $this->shopStep(fn (Shop $shop): Run => app(ReadPromisesInCode::class)->handle($shop->id)),
            'relations' => $this->shopStep(fn (Shop $shop): Run => app(ComputeProductRelations::class)->handle($shop->id)),
            // Bank completion: what the active bank does not cover, from every product; a report, or with --activate a new version.
            'complete' => $this->completeBank(),
            'rules' => $this->rules(),
            default => $this->failWith('Unknown step.'),
        });
    }

    private function collect(): int
    {
        $shops = $this->option('scheduled')
            // a demo's too: a batch it handed over before its products were read at once has answers waiting (15.11)
            ? Shop::query()->whereIn('status', [ShopStatus::Active, ShopStatus::Demo])->orderBy('slug')->get()
                ->filter(fn (Shop $shop): bool => $shop->status === ShopStatus::Demo || Features::enabled('enrichment.batch_reading', $shop->id))
            : collect([$this->shop()])->filter();

        if ($shops->isEmpty()) {
            return $this->option('scheduled') ? self::SUCCESS : $this->failWith('Shop not found.');
        }

        foreach ($shops as $shop) {
            $run = app(CollectProviderBatches::class)->handle($shop->id);

            if ($run !== null) {
                $this->line($shop->slug.': '.$run->summary());
            }
        }

        return self::SUCCESS;
    }

    /**
     * A proposal for the whole catalog, or with --branch for one branch of it, and the kinds of product it
     * proposes (read_by: model), so the person who approves it sees the list first.
     */
    private function schema(): int
    {
        $shop = $this->shop();

        if (! $shop) {
            return $this->failWith('Shop not found.');
        }

        $branch = null;
        if ($this->option('branch') !== null) {
            $branches = app(TenantContext::class)->run($shop->id, fn (): array => BranchPlan::of()['branches']);
            $branch = collect($branches)->firstWhere('key', (string) $this->option('branch'));

            if ($branch === null) {
                return $this->failWith('Branch not found. Branches: '.(implode(', ', array_map(fn (array $b): string => $b['key'].' ('.$b['name'].', '.$b['products'].')', $branches)) ?: 'none'));
            }
        }

        ['run' => $run, 'proposal' => $proposal] = app(DiscoverSchema::class)->handle($shop->id, null, $branch);
        $this->line((string) $run->summary());

        if ($proposal === null) {
            return $run->status->value === 'succeeded' ? self::SUCCESS : self::FAILURE;
        }

        $run = app(ProposeSchema::class)->handle($proposal);
        $this->line((string) $run->summary());
        $proposal = app(TenantContext::class)->run($shop->id, fn (): ?EnrichmentSchemaProposal => EnrichmentSchemaProposal::query()->find($proposal->id));

        foreach ((array) ($proposal?->proposed['attributes'] ?? []) as $attribute) {
            if (($attribute['read_by'] ?? null) === VocabularyDefinition::READ_BY_MODEL) {
                $this->line($attribute['key'].' ('.($attribute['label']['he'] ?? '').'): '.implode(', ', array_map(fn (array $v): string => $v['key'].' '.($v['label']['he'] ?? ''), (array) ($attribute['values'] ?? []))));
            }
        }
        if ($proposal !== null) {
            $this->line("Proposal {$proposal->id}: {$proposal->status}");
        }

        return $run->status->value === 'succeeded' ? self::SUCCESS : self::FAILURE;
    }

    private function completeBank(): int
    {
        $shop = $this->shop();
        if (! $shop) {
            return $this->failWith('Shop not found.');
        }
        // in the first scan, as every store's flag says (enrichment.bank_completion); asked by hand with --force, always
        if (! $this->option('force') && ! Features::enabled('enrichment.bank_completion', $shop->id)) {
            $this->line('Bank completion is off for this shop.');

            return self::SUCCESS;
        }
        $run = app(CompleteVocabulary::class)->handle($shop->id, (bool) $this->option('activate'), $report);
        $this->line((string) $run->summary());
        $this->line((string) json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return $run->status->value === 'succeeded' ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The expert bank, a dry run for one named shop: the run's lines step by step and where the report is kept; with
     * --show, a stored report as filters. Never for every shop, and nothing the store is read with changes.
     */
    private function expertBank(): int
    {
        $shop = $this->option('scheduled') ? null : $this->shop();
        if (! $shop) {
            return $this->failWith('Shop not found. The expert bank runs for one named shop only.');
        }
        $he = fn (string $key, array $params = []): string => (string) trans('enrichment::runs.'.$key, $params, 'he');

        if ($this->option('show')) {
            $stored = app(TenantContext::class)->run($shop->id, fn (): ?EnrichmentExpertBank => EnrichmentExpertBank::query()
                ->when($this->argument('argument'), fn ($q, $id) => $q->whereKey($id), fn ($q) => $q->where('status', '!=', EnrichmentExpertBank::FAILED))
                ->latest()->first());
            if ($stored === null) {
                return $this->failWith($he('expert_bank_show_none'));
            }

            return $this->showExpertBank($stored, $he);
        }

        if ($this->option('deactivate')) {
            return $this->deactivateExpertBank($shop, $he);
        }
        if ($this->option('activate')) {
            return $this->activateExpertBank($shop, $he);
        }

        if (! Features::enabled(BuildExpertBank::FEATURE, $shop->id)) {
            $this->line($he('expert_bank_off'));

            return self::SUCCESS;
        }

        ['run' => $run, 'bank' => $stored] = app(BuildExpertBank::class)->handle($shop->id, (bool) $this->option('force'));
        foreach ((array) ($run->output['steps'] ?? []) as $step) {
            $this->line((string) $step);
        }
        if (! in_array((string) $run->summary(), (array) ($run->output['steps'] ?? []), true)) {
            $this->line((string) $run->summary());
        }
        if ($stored !== null) {
            $this->showExpertBank($stored, $he);
        }
        // a demo's scan (FirstScan 'bank'): a bank that passed its checks is the store's vocabulary at once, and the
        // products are read by it in code, so the search built next files them by it. One that did not pass changes nothing.
        if ($this->option('activate-passed') && $stored !== null && $stored->status === EnrichmentExpertBank::PASSED) {
            // a bank already active (a scan again, a rebuild) gives way to the one that passed now
            if (EnrichmentExpertBank::query()->where('shop_id', $shop->id)->where('status', EnrichmentExpertBank::ACTIVATED)->exists()) {
                $this->line((string) app(ActivateExpertBank::class)->deactivate($shop->id)['run']->summary());
            }
            ['run' => $activated, 'vocabulary' => $vocabulary] = app(ActivateExpertBank::class)->activate($shop->id, (string) $stored->id);
            $this->line((string) $activated->summary());
            if ($vocabulary !== null && Features::enabled('enrichment.read_text_in_code', $shop->id)) {
                $this->line((string) app(ReadProductsInCode::class)->handle($shop->id)->summary());
            }
        }

        return $run->status->value === 'succeeded' ? self::SUCCESS : self::FAILURE;
    }

    /**
     * A stored bank that passed becomes the store's vocabulary; nothing is read with it here. The commands that turn it
     * into facts and filters are printed, in order, for the person to run: code reading, then the search index.
     *
     * @param  callable(string, array<string, scalar>=): string  $he
     */
    private function activateExpertBank(Shop $shop, callable $he): int
    {
        $id = (string) $this->argument('argument');
        if ($id === '') {
            return $this->failWith($he('expert_bank_activate_needs_id'));
        }

        ['run' => $run, 'vocabulary' => $vocabulary] = app(ActivateExpertBank::class)->activate($shop->id, $id);
        $this->line((string) $run->summary());
        if ($vocabulary === null) {
            return self::FAILURE;
        }
        if (! Features::enabled('enrichment.read_text_in_code', $shop->id)) {
            $this->warn($he('expert_bank_read_text_off'));
        }
        $this->nextSteps($shop, $he('expert_bank_next'));

        return self::SUCCESS;
    }

    /** @param  callable(string, array<string, scalar>=): string  $he */
    private function deactivateExpertBank(Shop $shop, callable $he): int
    {
        ['run' => $run, 'bank' => $bank] = app(ActivateExpertBank::class)->deactivate($shop->id);
        $this->line((string) $run->summary());
        if ($bank === null) {
            return self::FAILURE;
        }
        $this->nextSteps($shop, $he('expert_bank_next_undo'));

        return self::SUCCESS;
    }

    /** The code reading and the search index, the commands that make a vocabulary's facts and filters. No model is asked. */
    private function nextSteps(Shop $shop, string $intro): void
    {
        $this->line($intro);
        $this->line('  php artisan enrichment code '.$shop->slug);
        $this->line('  php artisan search index '.$shop->slug);
    }

    /** @param  callable(string, array<string, scalar>=): string  $he */
    private function showExpertBank(EnrichmentExpertBank $stored, callable $he): int
    {
        $this->info($he('expert_bank_show_stored', ['id' => $stored->id, 'status' => $stored->status]));
        foreach ((array) $stored->problems as $problem) {
            $this->line('  '.$problem);
        }
        if ($stored->bank === null || $stored->measurement === null) {
            return self::SUCCESS;
        }

        foreach (ExpertBankGate::filters($stored->bank, $stored->measurement, $he('expert_bank_show_type')) as $filter) {
            $this->line('');
            $this->line($filter['label'].' ('.$filter['products'].' '.$he('expert_bank_show_products').')');
            foreach ($filter['values'] as $value) {
                $this->line('  '.$value['label'].': '.$value['products']);
            }
        }

        $why = array_count_values(array_column((array) $stored->removed, 'why'));
        ksort($why, SORT_STRING);
        $this->line('');
        $this->line($he('expert_bank_show_removed', ['count' => count((array) $stored->removed)]));
        foreach ($why as $reason => $count) {
            $this->line('  '.$reason.': '.$count);
        }

        return self::SUCCESS;
    }

    private function vocabulary(): int
    {
        $shop = $this->shop();
        $data = $this->option('template')
            ? ImportVocabulary::template((string) $this->option('template'))
            : json_decode((string) @file_get_contents((string) $this->option('file')), true);

        if (! $shop || ! is_array($data)) {
            return $this->failWith('Shop or vocabulary not found.');
        }

        $result = app(ImportVocabulary::class)->handle($shop->id, $data, $this->option('author'));
        $this->line((string) $result['run']->summary());

        return $result['vocabulary'] ? self::SUCCESS : self::FAILURE;
    }

    private function tasks(): int
    {
        $shop = $this->shop();
        $type = TaskType::tryFrom((string) $this->argument('argument'));

        if (! $shop || ! $type) {
            return $this->failWith('Shop or task type not found.');
        }

        $vocabulary = EnrichmentVocabulary::query()->where('shop_id', $shop->id)->where('active', true)
            ->when($this->option('vocabulary'), fn ($q, $key) => $q->where('key', $key))
            ->latest('version')->first();

        $result = app(CreateTaskFile::class)->handle(
            $shop->id,
            $type,
            $vocabulary?->id,
            (int) $this->option('tier'),
            ($type === TaskType::FactReview ? ['subject' => (string) $this->option('subject')] : []) + ($this->option('include-done') ? ['include_done' => true] : []),
            $this->option('limit') !== null ? (int) $this->option('limit') : null,
        );

        $this->line((string) $result['run']->summary());

        if ($result['batch'] && $this->option('out')) {
            $handle = fopen((string) $this->option('out'), 'wb');
            foreach (TaskFile::lines($result['batch']) as $line) {
                fwrite($handle, $line);
            }
            fclose($handle);
            $this->info("Batch {$result['batch']->id} written to {$this->option('out')}");
        }

        return self::SUCCESS;
    }

    private function results(): int
    {
        $batch = EnrichmentBatch::query()->find((string) $this->argument('target'));
        $contents = @file_get_contents((string) $this->argument('argument'));

        if (! $batch || $contents === false) {
            return $this->failWith('Batch or results file not found.');
        }

        $run = app(ImportTaskResults::class)->handle($batch, $contents, $this->option('model'));
        $this->line((string) $run->summary());

        return $run->status->value === 'succeeded' ? self::SUCCESS : self::FAILURE;
    }

    private function rankings(): int
    {
        $shop = $this->shop();

        if (! $shop) {
            return $this->failWith('Shop not found.');
        }

        $this->line((string) app(ComputeRankings::class)->handle($shop->id)->summary());

        return self::SUCCESS;
    }

    /**
     * @param  callable(Shop): Run  $step
     * @param  string|null  $flag  the feature a shop opts out of the scheduled walk with
     */
    private function shopStep(callable $step, ?string $flag = null): int
    {
        if ($this->option('scheduled')) {
            return $flag === null ? $this->failWith('This step has no scheduled form.') : $this->everyShop($step, $flag);
        }

        $shop = $this->shop();

        if (! $shop) {
            return $this->failWith('Shop not found.');
        }

        $run = $step($shop);
        $this->line((string) $run->summary());

        return $run->status->value === 'succeeded' ? self::SUCCESS : self::FAILURE;
    }

    private function rules(): int
    {
        $shop = $this->shop();
        $data = $this->option('template')
            ? ImportRelationRules::template((string) $this->option('template'))
            : json_decode((string) @file_get_contents((string) $this->option('file')), true);

        if (! $shop || ! is_array($data)) {
            return $this->failWith('Shop or rules not found.');
        }

        $result = app(ImportRelationRules::class)->handle($shop->id, $data, $this->option('author'));
        $this->line((string) $result['run']->summary());

        return $result['rules'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The step for every shop that has articles to read, unless the shop turned the flag off.
     * A shop that fails does not stop the others; the scheduler sees one failure at the end.
     *
     * @param  callable(Shop): Run  $step
     */
    private function everyShop(callable $step, string $flag): int
    {
        $shopIds = $flag === 'enrichment.weekly_audit'
            ? CatalogContent::query()->active()->whereNotNull('body')->distinct()->pluck('shop_id')
            : CatalogProduct::query()->whereNull('removed_at')->distinct()->pluck('shop_id')
                ->concat(CatalogContent::query()->active()->distinct()->pluck('shop_id'))->unique();
        $failed = 0;

        foreach (Shop::query()->whereIn('id', $shopIds)->orderBy('slug')->get() as $shop) {
            if (! Features::enabled($flag, $shop->id)) {
                $this->line("{$shop->slug}: off");

                continue;
            }

            $run = $step($shop);
            $this->line("{$shop->slug}: ".$run->summary());
            $failed += $run->status->value === 'succeeded' ? 0 : 1;
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function words(): int
    {
        $shop = $this->shop();

        if (! $shop) {
            return $this->failWith('Shop not found.');
        }

        return app(TenantContext::class)->run($shop->id, function (): int {
            foreach (EnrichmentVocabulary::query()->where('active', true)->orderBy('key')->get() as $vocabulary) {
                $audit = WordAudit::of($vocabulary->definition(), VocabularyBranch::products($vocabulary->definition())->with('categories')->lazy(200));
                $this->info("{$vocabulary->key} v{$vocabulary->version}");
                $this->line('Found only inside longer words (code passes over these):');
                $this->table(['attribute', 'value', 'word', 'products', 'example'], array_map(fn (array $r): array => [$r['attribute'], $r['value'], $r['word'], $r['products'], $r['example']], $audit['inside']));
                $this->line('Names that group several values (code decides nothing from them):');
                $this->table(['attribute', 'name', 'values', 'products'], array_map(fn (array $r): array => [$r['attribute'], $r['name'], implode(', ', $r['values']), $r['products']], $audit['groups']));
            }

            return self::SUCCESS;
        });
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
