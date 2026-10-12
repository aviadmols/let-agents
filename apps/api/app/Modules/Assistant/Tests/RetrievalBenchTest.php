<?php

namespace App\Modules\Assistant\Tests;

use App\Modules\Assistant\Benchmark\RetrievalBench;
use App\Modules\Assistant\Benchmark\WriterBench;
use PHPUnit\Framework\TestCase;

/**
 * The retrieval benchmark's report: how much of what answers a question reached the writer, and
 * each wrong answer of the writer benchmark put on retrieval, facts or the writer.
 */
final class RetrievalBenchTest extends TestCase
{
    public function test_recall_counts_what_answers_among_what_each_net_found(): void
    {
        $report = RetrievalBench::report([
            // The search showed one of the two drills that answer; the meaning net found both, the second at 7.
            $this->record('a', relevant: ['product:1', 'product:2'], shown: ['product:1', 'product:9'], byMeaning: ['product:1', 'product:3', 'product:4', 'product:5', 'product:6', 'product:7', 'product:2']),
            // A delivery question: the page reached the writer; at 500 characters it is found, at 2000 it is not.
            $this->record('b', relevant: ['content:משלוחים'], docs: ['content:משלוחים'], sizes: [500 => ['content:משלוחים'], 1200 => ['content:משלוחים'], 2000 => ['content:אודות']]),
            // Nothing in the store answers: not counted in any recall.
            $this->record('c', relevant: [], answerable: false),
        ]);

        $this->assertSame([3, 2, 1], [$report['questions'], $report['answerable_in_store'], $report['not_answerable_in_store']], 'the unanswerable question is counted apart, and in no recall');
        $this->assertSame(1, $report['products']['questions_with_a_relevant_product']);
        $this->assertSame(50.0, $report['products']['production_recall@shown']);
        $this->assertSame(100.0, $report['products']['production_hit@shown']);
        $this->assertSame(50.0, $report['products']['by_meaning_recall@5']);
        $this->assertSame(100.0, $report['products']['by_meaning_recall@12']);
        $this->assertSame(100.0, $report['pages']['production_recall']);
        $this->assertSame(100.0, $report['pages']['size_500_recall@3']);
        $this->assertSame(0.0, $report['pages']['size_2000_recall@3']);
    }

    public function test_each_wrong_answer_is_put_on_one_layer(): void
    {
        $records = [
            // Answerable, and nothing that answers reached the writer: retrieval.
            $this->record('miss', relevant: ['product:2'], shown: ['product:9']),
            // It reached the writer: the facts-only writer failed where the current one did not: facts.
            $this->record('facts', relevant: ['product:1'], shown: ['product:1']),
            // It reached the writer and both failed: the writer.
            $this->record('writer', relevant: ['product:1'], shown: ['product:1']),
            // The store holds no answer: saying nothing is right, an answer is made up.
            $this->record('none', relevant: [], answerable: false),
        ];
        $judged = [
            'miss' => $this->judged('miss', ['current' => false, 'current@v5' => false]),
            'facts' => $this->judged('facts', ['current' => true, 'current@v5' => false]),
            'writer' => $this->judged('writer', ['current' => false, 'current@v5' => false]),
            'none' => $this->judged('none', ['current' => null, 'current@v5' => false]),
        ];

        $attribution = RetrievalBench::report($records, $judged)['attribution'];

        $this->assertSame(['correct' => 1, 'no_answer_refused' => 1, 'retrieval' => 1, 'facts' => 0, 'writer' => 1, 'no_answer_invented' => 0, 'failed_call' => 0], $attribution[WriterBench::CURRENT]);
        $this->assertSame(['correct' => 0, 'no_answer_refused' => 0, 'retrieval' => 1, 'facts' => 1, 'writer' => 1, 'no_answer_invented' => 1, 'failed_call' => 0], $attribution['current@'.WriterBench::V5]);
    }

    /** @return array<string, mixed> */
    private function record(string $id, array $relevant, array $shown = [], array $docs = [], array $byMeaning = [], array $sizes = [], ?bool $answerable = null): array
    {
        return [
            'id' => $id, 'shop_id' => 's', 'question' => 'q', 'language' => 'he',
            'production' => ['products' => $shown, 'docs' => $docs],
            'nets' => ['products_by_meaning' => $byMeaning, 'products_by_name' => [], 'passages_wide' => $docs],
            'sizes' => array_map(fn (array $d): array => ['docs' => $d, 'chars_top6' => 100], $sizes),
            'candidates' => [],
            'labels' => ['answerable_in_store' => $answerable ?? $relevant !== [], 'relevant' => $relevant, 'note' => ''],
        ];
    }

    /**
     * A judged writer-benchmark record: each writer answered, right or wrong by the judge, or (null) said nothing.
     *
     * @param  array<string, bool|null>  $right
     * @return array<string, mixed>
     */
    private function judged(string $id, array $right): array
    {
        $writers = [];
        $verdicts = [];
        foreach ($right as $key => $ok) {
            $writers[$key] = ['answer' => $ok === null ? '' : 'תשובה', 'picks' => [], 'check' => ['verdict' => 'kept'], 'code' => ['internal_ids' => [], 'foreign_script' => [], 'language_ok' => true]];
            $verdicts[$key] = ['answer_correct' => $ok, 'hallucination' => ! $ok, 'wrong_attribution' => false, 'language_correct' => true];
        }

        return ['id' => $id, 'writers' => $writers, 'judge' => ['answerable' => true, 'verdicts' => $verdicts]];
    }
}
