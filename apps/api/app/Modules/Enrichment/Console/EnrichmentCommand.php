<?php

namespace App\Modules\Enrichment\Console;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Actions\AuditContentReading;
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
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Enrichment\Scanning\WordAudit;
use App\Modules\Enrichment\Support\TaskFile;
use App\Modules\Enrichment\Support\VocabularyBranch;
use App\Modules\Runs\Models\Run;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Console\Command;

/**
 * The same actions the operator panel runs, for scripts and local work:
 *
 *   enrichment vocabulary gueta-avigdor --template=power-tools
 *   enrichment code gueta-avigdor
 *   enrichment tasks gueta-avigdor product_extraction --vocabulary=wood --limit=20 --out=tasks.jsonl
 *   enrichment results <batch id> answers.jsonl --model=claude-haiku-4-5
 *   enrichment rankings gueta-avigdor
 *   enrichment rules gueta-avigdor --template=hardware-store
 *   enrichment relations gueta-avigdor
 */
final class EnrichmentCommand extends Command
{
    protected $signature = 'enrichment
        {step : nightly, vocabulary, schema, code, words, content, audit, promises, tasks, results, rankings, rules or relations}
        {target? : shop slug, or batch ID for results; none with --scheduled}
        {argument? : task type for tasks, results file for results}
        {--template= : vocabulary template name}
        {--file= : vocabulary JSON file}
        {--author= : who wrote the vocabulary}
        {--vocabulary= : vocabulary key, for tasks}
        {--tier=1 : review tier}
        {--subject=product : product or content, for reviews}
        {--limit= : maximum requests}
        {--out= : where to write the task file}
        {--model= : the model that produced the results}
        {--include-done : ask again about products already read with the same input}
        {--scheduled : every shop that has articles, when its flag is on (audit only)}';

    protected $description = 'Run enrichment steps from the command line.';

    public function handle(TenantContext $tenant): int
    {
        return $tenant->runUnscoped(fn (): int => match ($this->argument('step')) {
            'vocabulary' => $this->vocabulary(),
            'tasks' => $this->tasks(),
            'results' => $this->results(),
            'nightly' => $this->shopStep(fn (Shop $shop): Run => app(RunNightlyReading::class)->handle($shop->id), 'enrichment.nightly'),
            'rankings' => $this->rankings(),
            'code' => $this->shopStep(fn (Shop $shop): Run => app(ReadProductsInCode::class)->handle($shop->id)),
            // Read only: where the vocabulary's words would mislead code (WordAudit).
            'words' => $this->words(),
            // The shop's own vocabulary: counted in code, then written and reviewed, for the operator to approve.
            'schema' => $this->shopStep(function (Shop $shop): Run {
                ['run' => $run, 'proposal' => $proposal] = app(DiscoverSchema::class)->handle($shop->id);
                $this->line((string) $run->summary());

                return $proposal === null ? $run : app(ProposeSchema::class)->handle($proposal);
            }),
            'audit' => $this->shopStep(fn (Shop $shop): Run => app(AuditContentReading::class)->handle($shop->id), 'enrichment.weekly_audit'),
            'content' => $this->shopStep(fn (Shop $shop): Run => app(ReadContentInCode::class)->handle($shop->id)),
            'promises' => $this->shopStep(fn (Shop $shop): Run => app(ReadPromisesInCode::class)->handle($shop->id)),
            'relations' => $this->shopStep(fn (Shop $shop): Run => app(ComputeProductRelations::class)->handle($shop->id)),
            'rules' => $this->rules(),
            default => $this->failWith('Unknown step.'),
        });
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
