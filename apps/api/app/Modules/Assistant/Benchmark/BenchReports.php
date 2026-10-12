<?php

namespace App\Modules\Assistant\Benchmark;

use App\Core\Tenancy\TenantContext;
use App\Modules\Assistant\Models\AssistantBenchReport;

/**
 * The benchmarks' results kept per shop, for the operator's page of that shop: the summary as the
 * report prints it, and one short row per question. Written when a summary or a report is made;
 * the same run's report is written over, never twice.
 */
final class BenchReports
{
    private const TEXT = 300;

    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * The writer benchmark, judged.
     *
     * @param  list<array<string, mixed>>  $records
     * @return int the shops written
     */
    public function factSheet(string $label, array $records): int
    {
        $judged = array_values(array_filter($records, fn (array $r): bool => isset($r['judge'])));
        $byShop = [];
        foreach ($judged as $r) {
            $byShop[$r['shop_id']][] = $r;
        }

        foreach ($byShop as $shopId => $shopRecords) {
            $rows = array_map(fn (array $r): array => [
                'id' => $r['id'],
                'question' => $r['question'],
                'answerable' => (bool) $r['judge']['answerable'],
                'answerable_note' => mb_substr((string) ($r['judge']['answerable_note'] ?? ''), 0, self::TEXT),
                'best' => $r['judge']['best'] ?? null,
                'writers' => array_map(fn (string $key): array => self::side($r, $key), array_combine(array_keys($r['writers']), array_keys($r['writers']))),
            ], $shopRecords);

            $this->save((string) $shopId, AssistantBenchReport::FACTSHEET, $label, WriterBench::summary($shopRecords), $rows);
        }

        return count($byShop);
    }

    /**
     * The retrieval benchmark, labelled; with the writer benchmark's judged records, by id, to put
     * each answer on a layer.
     *
     * @param  list<array<string, mixed>>  $records
     * @param  array<string, array<string, mixed>>  $judged
     * @return int the shops written
     */
    public function retrieval(string $label, array $records, array $judged): int
    {
        $byShop = [];
        foreach (array_filter($records, fn (array $r): bool => isset($r['labels'])) as $r) {
            $byShop[$r['shop_id']][] = $r;
        }

        foreach ($byShop as $shopId => $shopRecords) {
            $rows = array_map(function (array $r) use ($judged): array {
                $reached = array_merge($r['production']['products'], $r['production']['docs']);
                $relevant = $r['labels']['relevant'];
                $title = fn (string $key): string => (string) ($r['candidates'][$key]['title'] ?? $key);

                return [
                    'id' => $r['id'],
                    'question' => $r['question'],
                    'answerable_in_store' => (bool) $r['labels']['answerable_in_store'],
                    'note' => (string) $r['labels']['note'],
                    'relevant' => count($relevant),
                    'reached' => count(array_intersect($relevant, $reached)),
                    'missed' => array_map($title, array_slice(array_values(array_diff($relevant, $reached)), 0, 5)),
                    'layers' => isset($judged[$r['id']]) ? RetrievalBench::layers($r, $judged[$r['id']]) : [],
                ];
            }, $shopRecords);

            $this->save((string) $shopId, AssistantBenchReport::RETRIEVAL, $label, RetrievalBench::report($shopRecords, $judged), $rows);
        }

        return count($byShop);
    }

    /** @return array<string, mixed> one writer on one question, short */
    private static function side(array $record, string $key): array
    {
        $side = $record['writers'][$key];
        if (isset($side['error'])) {
            return ['error' => (string) $side['error']];
        }
        $verdict = (array) ($record['judge']['verdicts'][$key] ?? []);
        $titles = array_column(array_values((array) ($record['context']['productsByRef'] ?? [])), 'title', 'external_id');

        return [
            'answer' => mb_substr((string) $side['answer'], 0, self::TEXT),
            'picks' => array_map(fn ($id): string => (string) ($titles[$id] ?? $id), (array) $side['picks']),
            'shown' => WriterBench::shown($record, $key),
            'correct' => WriterBench::finalCorrect($record, $key),
            'hallucination' => ($verdict['hallucination'] ?? false) === true,
            'wrong_attribution' => ($verdict['wrong_attribution'] ?? false) === true,
            'note' => mb_substr((string) ($verdict['note'] ?? ''), 0, self::TEXT),
            'refusal' => $side['refusal'] ?? null,
            'checker' => $side['check']['verdict'] ?? null,
            'latency_ms' => $side['latency_ms'] ?? null,
        ];
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function save(string $shopId, string $kind, string $label, array $summary, array $rows): void
    {
        $this->tenant->run($shopId, fn () => AssistantBenchReport::query()->updateOrCreate(
            ['shop_id' => $shopId, 'kind' => $kind, 'label' => $label],
            ['questions' => count($rows), 'summary' => $summary, 'rows' => $rows],
        ));
    }
}
