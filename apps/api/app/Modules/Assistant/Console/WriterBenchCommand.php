<?php

namespace App\Modules\Assistant\Console;

use App\Core\Tenancy\TenantContext;
use App\Modules\Assistant\Benchmark\LocalWriter;
use App\Modules\Assistant\Benchmark\WriterBench;
use App\Modules\Assistant\Models\AssistantSearchAsk;
use App\Modules\Assistant\Support\Question;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use Illuminate\Console\Command;

/**
 * The search box's writer against other writers, on the questions shoppers asked (see WriterBench).
 *
 *   assistant:writer-bench snapshot q.jsonl --limit=100   production: context + current writer + checker
 *   assistant:writer-bench write q.jsonl                  another writer (LOCAL_AI_*), into q.writers.jsonl; once per model
 *   assistant:writer-bench judge q.writers.jsonl          production: the checker on each writer, one blind judge, into q.judged.jsonl
 *   assistant:writer-bench summary q.judged.jsonl         the report, also as q.summary.json
 *
 * A bare file name lives in storage/app/bench. Every step skips what is already in its output,
 * so a stopped run goes on. Nothing is shown to shoppers, saved as an answer or cached; the
 * platform's models are accounted on runs of assistant.writer_bench.
 */
final class WriterBenchCommand extends Command
{
    public const AGENT = 'assistant.writer_bench';

    protected $signature = 'assistant:writer-bench {step : snapshot, write, judge or summary} {file : JSON lines, in storage/app/bench unless a path}
        {--limit=100 : snapshot: how many questions} {--shop= : snapshot: one shop\'s questions only (id)}
        {--ids= : snapshot: these asks only, comma separated, so a set can be asked again}
        {--prompt=v4 : write: v4 (the search box\'s input) or v5 (facts only, FactSheet)}';

    protected $description = 'Compare the search box\'s writer with other writers on the questions shoppers asked, with the same input.';

    public function handle(WriterBench $bench, RecordsRuns $runs, TenantContext $tenant): int
    {
        $file = self::path((string) $this->argument('file'));

        return match ((string) $this->argument('step')) {
            'snapshot' => $this->snapshot($bench, $runs, $tenant, $file),
            'write' => $this->write($bench, $file),
            'judge' => $this->judge($bench, $runs, $file),
            'summary' => $this->summary($file),
            default => $this->refuse('The step is snapshot, write, judge or summary.'),
        };
    }

    private function snapshot(WriterBench $bench, RecordsRuns $runs, TenantContext $tenant, string $file): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $done = self::read($file);
        $seen = array_flip(array_map(fn (array $r): string => $r['shop_id'].'|'.Question::key($r['question']), $done));
        $have = array_flip(array_column($done, 'id'));
        $written = count($done);

        // Questions a model answered (or tried to) in the search box, the latest first, each wording once per shop.
        $asks = $tenant->runUnscoped(fn () => AssistantSearchAsk::query()
            ->where('from', 'model')->whereIn('outcome', ['answered', 'no_match', 'no_info'])
            ->when($this->option('shop'), fn ($q, $shop) => $q->where('shop_id', $shop))
            ->when($this->option('ids'), fn ($q, $ids) => $q->whereIn('id', array_filter(array_map('trim', explode(',', (string) $ids)))))
            ->latest()->limit($this->option('ids') ? 1000 : $limit * 4)->get());
        $limit = $this->option('ids') ? $asks->count() : $limit;

        foreach ($asks as $ask) {
            $key = $ask->shop_id.'|'.Question::key((string) $ask->question);
            if ($written >= $limit || isset($have[$ask->id]) || isset($seen[$key]) || $ask->question === '[support]') {
                continue;
            }
            $seen[$key] = true;
            $record = null;

            $runs->track(self::AGENT, 'assistant.bench_snapshot', function (RunContext $run) use ($bench, $ask, &$record): void {
                $record = $bench->snapshot($ask, $run);
                $run->output(['ask' => $ask->id, 'kept' => $record !== null]);
            }, $ask->shop_id, ['ask' => $ask->id]);

            if ($record === null) {
                $this->line("skip  {$ask->id}  (no context today)");

                continue;
            }

            self::append($file, $record);
            $written++;
            $this->line("{$written}/{$limit}  ".mb_substr($record['question'], 0, 60).'  ->  '.($record['writers'][WriterBench::CURRENT]['check']['verdict'] ?? '?'));
        }

        $this->info("{$written} questions in {$file}");

        return self::SUCCESS;
    }

    private function write(WriterBench $bench, string $file): int
    {
        if (! LocalWriter::enabled()) {
            return $this->refuse('Set LOCAL_AI_ENABLED=true and LOCAL_AI_MODEL first.');
        }

        $version = (string) $this->option('prompt') === WriterBench::V5 ? WriterBench::V5 : 'v4';
        $model = LocalWriter::model().($version === WriterBench::V5 ? '@'.WriterBench::V5 : '');
        $out = str_ends_with($file, '.writers.jsonl') ? $file : self::sibling($file, 'writers');
        $records = self::byId(self::read($out)) + self::byId(self::read($file));
        $writer = new LocalWriter;
        $n = 0;

        foreach ($records as $id => $record) {
            $n++;
            if (isset($record['writers'][$model])) {
                continue;
            }
            $records[$id]['writers'][$model] = $side = $bench->write($record, $writer, $version);
            self::rewrite($out, $records);
            $this->line("{$n}/".count($records).'  '.round(($side['latency_ms'] ?? 0) / 1000, 1).'s  '
                .(isset($side['error']) ? 'ERROR '.$side['error'] : mb_substr((string) $side['answer'], 0, 70)));
        }

        $this->info("{$model} written into {$out}");

        return self::SUCCESS;
    }

    private function judge(WriterBench $bench, RecordsRuns $runs, string $file): int
    {
        $out = self::sibling(preg_replace('~\.writers\.jsonl$~', '.jsonl', $file) ?? $file, 'judged');
        $records = self::byId(self::read($out)) + self::byId(self::read($file));

        foreach ($records as $id => $record) {
            if (isset($record['judge'])) {
                continue;
            }

            $runs->track(self::AGENT, 'assistant.bench_judge', function (RunContext $run) use ($bench, &$record): void {
                $record = $bench->judge($record, $run);
                $run->output(['ask' => $record['id'], 'best' => $record['judge']['best'] ?? null]);
            }, $record['shop_id'], ['ask' => $record['id']]);

            $records[$id] = $record;
            self::rewrite($out, $records);
            $this->line(mb_substr($record['question'], 0, 50).'  ->  best: '.($record['judge']['best'] ?? 'not judged'));
        }

        return $this->summary($out);
    }

    private function summary(string $file): int
    {
        $summary = WriterBench::summary(self::read($file));
        file_put_contents(self::sibling(preg_replace('~\.judged\.jsonl$~', '.jsonl', $file) ?? $file, 'summary', 'json'), json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $writers = $summary['writers'];
        $rows = [];

        foreach (['final_accuracy_after_checker_pct', 'raw_accuracy_pct', 'hallucination_pct', 'wrong_attribution_pct', 'code_wrong_owner', 'coverage_pct', 'missed_answer_pct',
            'correct_product_selection_pct', 'internal_id_leak_pct', 'language_issue_pct', 'checker_intervention_pct', 'checker_rejection_pct', 'best_by_judge'] as $metric) {
            $rows[] = [$metric, ...array_map(fn (array $w): string => (string) ($w[$metric] ?? '-'), $writers)];
        }
        foreach (['avg', 'p95'] as $at) {
            $rows[] = ["latency {$at} (s)", ...array_map(fn (array $w): string => isset($w['latency_ms'][$at]) ? (string) round($w['latency_ms'][$at] / 1000, 1) : '-', $writers)];
        }
        foreach (['in', 'out'] as $part) {
            $rows[] = ["tokens {$part} (avg)", ...array_map(fn (array $w): string => (string) ($w['tokens_avg'][$part] ?? '-'), $writers)];
        }
        foreach (['writer', 'checker', 'total'] as $part) {
            $rows[] = ["cost per question: {$part} (\$)", ...array_map(fn (array $w): string => (string) ($w['cost_per_question_usd'][$part] ?? '-'), $writers)];
        }

        $this->line("{$summary['questions']} questions, {$summary['answerable']} answerable from the input; judge cost \${$summary['judge_cost_usd']}");
        $this->table(['', ...array_keys($writers)], $rows);

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

    /** q.jsonl -> q.writers.jsonl */
    private static function sibling(string $file, string $suffix, string $extension = 'jsonl'): string
    {
        return (preg_replace('~\.jsonl$~', '', $file) ?? $file).'.'.$suffix.'.'.$extension;
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return array<string, array<string, mixed>>
     */
    private static function byId(array $records): array
    {
        return array_column($records, null, 'id');
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
