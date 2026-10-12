<?php

namespace App\Modules\Retrieval\Support;

use App\Core\Facades\Settings;
use App\Modules\Ai\Contracts\Embedder;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Retrieval\Contracts\ChunkExperiments;
use App\Modules\Retrieval\Contracts\Passages;
use App\Modules\Retrieval\Sources\ContentDocuments;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Enums\RunTrigger;

/**
 * The pages and articles cut at another size and embedded in memory, with the index's own model,
 * once per shop and size; the products' stored pieces as they are. Nothing is written to the
 * index. The embedding is accounted on one run per shop and size.
 */
final class InMemoryChunkExperiments implements ChunkExperiments
{
    public const AGENT = 'retrieval.chunk_experiment';

    private const BATCH = 100;

    private const CHARS_PER_TOKEN = 3;

    /** Two pieces of one document at most, as PassageSearch keeps them. */
    private const PER_DOCUMENT = 2;

    /** @var array<string, list<array{source: string, source_id: string, external_id: string|null, title: string, text: string, vector: list<float>}>> by shop and size */
    private array $pieces = [];

    private float $spent = 0.0;

    public function __construct(
        private readonly ContentDocuments $content,
        private readonly Passages $passages,
        private readonly Embedder $embedder,
        private readonly SpendGuard $spend,
        private readonly RecordsRuns $runs,
    ) {}

    public function near(string $shopId, string $text, int $chunkChars, array $sources, int $limit): array
    {
        $vector = app(QueryVectors::class)->for($shopId, $text);

        if ($vector === null || $limit < 1) {
            return [];
        }

        $rows = [];

        // The products' stored pieces, nearest first, a generous few more than asked.
        if (in_array('product', $sources, true)) {
            $rows = $this->passages->near($shopId, $text, ['product'], $limit * 4);
        }

        if (in_array('content', $sources, true)) {
            $seed = VectorSearch::normalize($vector);
            foreach ($this->pieces($shopId, $chunkChars) as $piece) {
                if (count($piece['vector']) !== count($seed)) {
                    continue;
                }
                $dot = 0.0;
                foreach ($seed as $i => $value) {
                    $dot += $value * $piece['vector'][$i];
                }
                $rows[] = array_diff_key($piece, ['vector' => 0]) + ['similarity' => round($dot, 4)];
            }
        }

        usort($rows, fn (array $a, array $b): int => [$b['similarity'], $a['source_id']] <=> [$a['similarity'], $b['source_id']]);
        $out = [];
        $perDocument = [];
        foreach ($rows as $row) {
            $key = $row['source'].'|'.$row['source_id'];
            if (($perDocument[$key] ?? 0) >= self::PER_DOCUMENT) {
                continue;
            }
            $perDocument[$key] = ($perDocument[$key] ?? 0) + 1;
            $out[] = $row;
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    public function spent(): float
    {
        return round($this->spent, 6);
    }

    /**
     * The shop's pages and articles cut at this size and embedded, once.
     *
     * @return list<array{source: string, source_id: string, external_id: string|null, title: string, text: string, vector: list<float>}>
     */
    private function pieces(string $shopId, int $chunkChars): array
    {
        $key = $shopId.'|'.$chunkChars;
        if (isset($this->pieces[$key])) {
            return $this->pieces[$key];
        }

        $pieces = [];
        foreach ($this->content->documents($shopId) as $document) {
            foreach (Chunker::split($document->title, $document->text, $chunkChars) as $text) {
                $pieces[] = ['source' => 'content', 'source_id' => (string) $document->sourceId, 'external_id' => $document->externalId, 'title' => $document->title, 'text' => $text, 'vector' => []];
            }
        }

        $choice = ModelChoice::for('embedding');
        $dimensions = (int) Settings::get('retrieval.embedding_dimensions') ?: null;
        $price = (float) Settings::get('retrieval.embedding_usd_per_million');

        $this->runs->track(self::AGENT, 'retrieval.chunk_experiment', function (RunContext $run) use (&$pieces, $choice, $dimensions, $price, $chunkChars): void {
            if ($choice->provider === null) {
                $run->fail('retrieval::runs.chunk_experiment_no_provider');

                return;
            }
            foreach (array_chunk(array_keys($pieces), self::BATCH) as $batch) {
                $texts = array_map(fn (int $i): string => $pieces[$i]['text'], $batch);
                $this->spend->assertCanSpend(array_sum(array_map('mb_strlen', $texts)) / self::CHARS_PER_TOKEN * $price / 1_000_000);
                $embeddings = $this->embedder->embed($choice->provider, $choice->model, $texts, $dimensions);
                $cost = $embeddings->costUsd($price);
                $run->usage($choice->providerName, $choice->model, $embeddings->inputTokens, 0, 0, $cost);
                $this->spent += $cost;
                foreach ($batch as $at => $i) {
                    $pieces[$i]['vector'] = VectorSearch::normalize($embeddings->vectors[$at] ?? []);
                }
            }
            $run->output(['chunk_chars' => $chunkChars, 'pieces' => count($pieces)])->summary('retrieval::runs.chunk_experiment', ['pieces' => (string) count($pieces), 'chars' => (string) $chunkChars]);
        }, $shopId, ['chunk_chars' => $chunkChars], RunTrigger::Manual);

        return $this->pieces[$key] = array_values(array_filter($pieces, fn (array $p): bool => $p['vector'] !== []));
    }
}
