<?php

namespace App\Modules\Assistant\Console;

use App\Core\Tenancy\TenantContext;
use App\Modules\Assistant\Benchmark\RetrievalBench;
use App\Modules\Assistant\Models\AssistantSearchAsk;
use App\Modules\Retrieval\Contracts\ChunkExperiments;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use Illuminate\Console\Command;

/**
 * Where a wrong answer comes from: retrieval, facts or the writer (see RetrievalBench).
 *
 *   assistant:retrieval-bench pool r.jsonl --ids=...               production: what reached the writer, and the wider nets
 *   assistant:retrieval-bench label r.jsonl                        production: one judge per question, into r.labelled.jsonl
 *   assistant:retrieval-bench report r.labelled.jsonl --judged=q.judged.jsonl   recall@k, the piece sizes, and the writer benchmark's errors by layer
 *
 * A bare file name lives in storage/app/bench. Every step skips what is already in its output.
 * Nothing is written to the index, shown to shoppers or cached; the judge is accounted on runs of
 * assistant.retrieval_bench, the pages cut again on runs of retrieval.chunk_experiment.
 */
final class RetrievalBenchCommand extends Command
{
    public const AGENT = 'assistant.retrieval_bench';

    protected $signature = 'assistant:retrieval-bench {step : pool, label or report} {file : JSON lines, in storage/app/bench unless a path}
        {--ids= : pool: these asks, comma separated (the writer benchmark\'s questions)}
        {--judged= : report: the writer benchmark\'s judged file, to put its errors on a layer}';

    protected $description = 'Measure what the search box\'s writer was given against what the store holds, on the writer benchmark\'s questions.';

    public function handle(RetrievalBench $bench, RecordsRuns $runs, TenantContext $tenant, ChunkExperiments $chunks): int
    {
        $file = self::path((string) $this->argument('file'));

        return match ((string) $this->argument('step')) {
            'pool' => $this->pool($bench, $tenant, $chunks, $file),
            'label' => $this->label($bench, $runs, $file),
            'report' => $this->report($file),
            default => $this->refuse('The step is pool, label or report.'),
        };
    }

    private function pool(RetrievalBench $bench, TenantContext $tenant, ChunkExperiments $chunks, string $file): int
    {
        $ids = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('ids')))));
        if ($ids === []) {
            return $this->refuse('Name the asks with --ids, the same as the writer benchmark\'s.');
        }
        $have = array_flip(array_column(self::read($file), 'id'));
        $asks = $tenant->runUnscoped(fn () => AssistantSearchAsk::query()->whereIn('id', $ids)->orderBy('id')->get());
        $n = 0;

        foreach ($asks as $ask) {
            $n++;
            if (isset($have[$ask->id])) {
                continue;
            }
            $record = $bench->pool($ask);
            if ($record === null) {
                $this->line("skip  {$ask->id}  (no context today)");

                continue;
            }
            self::append($file, $record);
            $this->line("{$n}/{$asks->count()}  ".mb_substr($record['question'], 0, 50).'  ->  '.count($record['candidates']).' candidates');
        }

        $this->info("pages cut again: \${$chunks->spent()}");

        return self::SUCCESS;
    }

    private function label(RetrievalBench $bench, RecordsRuns $runs, string $file): int
    {
        $out = self::sibling($file, 'labelled');
        $records = array_column(self::read($out), null, 'id') + array_column(self::read($file), null, 'id');

        foreach ($records as $id => $record) {
            if (isset($record['labels'])) {
                continue;
            }
            $runs->track(self::AGENT, 'assistant.bench_label', function (RunContext $run) use ($bench, &$record): void {
                $record = $bench->label($record, $run);
                $run->output(['ask' => $record['id'], 'relevant' => count($record['labels']['relevant'])]);
            }, $record['shop_id'], ['ask' => $record['id']]);

            if (! isset($record['labels'])) {
                $this->line(mb_substr($record['question'], 0, 50).'  ->  not labelled (the judge failed); asked again on the next run');

                continue;
            }
            $records[$id] = $record;
            self::rewrite($out, $records);
            $this->line(mb_substr($record['question'], 0, 50).'  ->  '.count($record['labels']['relevant']).' relevant'.($record['labels']['answerable_in_store'] ? '' : ', not answerable'));
        }

        return $this->report($out);
    }

    private function report(string $file): int
    {
        $judged = $this->option('judged') ? array_column(self::read(self::path((string) $this->option('judged'))), null, 'id') : [];
        $report = RetrievalBench::report(self::read($file), $judged);
        file_put_contents(preg_replace('~(\.labelled)?\.jsonl$~', '', $file).'.report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->line("{$report['questions']} questions: {$report['answerable_in_store']} answerable from what the store holds, {$report['not_answerable_in_store']} with no answer in the store. Recall below is over the answerable ones only.");
        foreach (['reached', 'products', 'pages'] as $part) {
            $this->table([$part, '%'], array_map(fn (string $k, mixed $v): array => [$k, (string) $v], array_keys($report[$part]), array_values($report[$part])));
        }
        if (isset($report['attribution'])) {
            $layers = [...RetrievalBench::LAYERS, 'correct_from_partial'];
            $this->table(['writer', ...$layers], array_map(fn (string $w, array $c): array => [$w, ...array_map(fn (string $l): string => (string) $c[$l], $layers)], array_keys($report['attribution']), array_values($report['attribution'])));
        }

        return self::SUCCESS;
    }

    private function refuse(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }

    private static function path(string $file): string
    {
        if (str_contains($file, '/') || str_contains($file, '\\')) {
            return $file;
        }
        @mkdir(storage_path('app/bench'), 0775, true);

        return storage_path('app/bench/'.$file);
    }

    /** r.jsonl -> r.labelled.jsonl */
    private static function sibling(string $file, string $suffix): string
    {
        return (preg_replace('~\.jsonl$~', '', $file) ?? $file).'.'.$suffix.'.jsonl';
    }

    /** @return list<array<string, mixed>> */
    private static function read(string $file): array
    {
        if (! is_file($file)) {
            return [];
        }

        return array_values(array_filter(array_map(fn (string $line): mixed => json_decode($line, true), file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []), 'is_array'));
    }

    private static function append(string $file, array $record): void
    {
        file_put_contents($file, json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND | LOCK_EX);
    }

    /** @param  array<string, array<string, mixed>>  $records */
    private static function rewrite(string $file, array $records): void
    {
        file_put_contents($file, implode('', array_map(fn (array $r): string => json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n", $records)), LOCK_EX);
    }
}
