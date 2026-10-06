<?php

namespace App\Modules\Retrieval\Actions;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\Embedder;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\SpendCapReached;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Retrieval\Contracts\DocumentSource;
use App\Modules\Retrieval\Models\RetrievalChunk;
use App\Modules\Retrieval\Support\Chunker;
use App\Modules\Retrieval\Support\ModelChoice;
use App\Modules\Retrieval\Support\VectorSearch;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Models\Run;

/**
 * A shop's index, brought up to date: every source read again in code, cut into pieces, and
 * only the pieces whose text changed (or whose vector came from another model) sent to the
 * embedding model.
 *
 *   1. read      every DocumentSource tagged retrieval.sources: products, pages and posts,
 *                purchases, and whatever another module adds
 *   2. cut       Chunker, at paragraphs and sentences, the title on every piece
 *   3. compare   a piece whose text hash is unchanged keeps its vector; a source document that
 *                is gone takes its pieces with it
 *   4. embed     the pieces without a vector of the current model, in batches, each batch
 *                asking SpendGuard first and recording its cost on the run
 *
 * Stopping early is not failing: a run that hit the spending cap or found no key leaves the
 * rest pending for the next run and says why.
 */
final class BuildIndex
{
    public const AGENT = 'retrieval.indexer';

    public const ACTION = 'retrieval.build_index';

    private const BATCH = 64;

    /** Hebrew runs at about two characters a token; estimating high is the safe side. */
    private const CHARS_PER_TOKEN = 2;

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
        private readonly Embedder $embedder,
        private readonly SpendGuard $spend,
    ) {}

    public function handle(string $shopId): Run
    {
        return $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            work: fn (RunContext $run) => $this->tenant->run($shopId, fn () => $this->build($run, $shopId)),
        );
    }

    private function build(RunContext $run, string $shopId): void
    {
        $maxChars = (int) Settings::get('retrieval.chunk_chars');
        $sources = [];

        foreach (app()->tagged('retrieval.sources') as $source) {
            /** @var DocumentSource $source */
            $sources[$source->key()] = $this->sync($shopId, $source, $maxChars);
        }

        $embedding = $this->embed($run, $shopId);

        $run->output(['sources' => $sources, 'embedding' => $embedding])
            ->summary('retrieval::runs.indexed', [
                'documents' => (string) array_sum(array_column($sources, 'documents')),
                'chunks' => (string) array_sum(array_column($sources, 'chunks')),
                'embedded' => (string) $embedding['embedded'],
                'pending' => (string) $embedding['pending'],
            ]);
    }

    /** @return array{documents: int, chunks: int, changed: int, removed: int} */
    private function sync(string $shopId, DocumentSource $source, int $maxChars): array
    {
        $key = $source->key();
        $existing = [];

        foreach (RetrievalChunk::query()->where('source', $key)->get(['id', 'source_id', 'position', 'text_hash']) as $chunk) {
            $existing[$chunk->source_id][$chunk->position] = $chunk;
        }

        $stats = ['documents' => 0, 'chunks' => 0, 'changed' => 0, 'removed' => 0];
        $stale = [];

        foreach ($source->documents($shopId) as $document) {
            $pieces = Chunker::split($document->title, $document->text, $maxChars);
            $had = $existing[$document->sourceId] ?? [];
            unset($existing[$document->sourceId]);

            if ($pieces === []) {
                array_push($stale, ...array_map(fn (RetrievalChunk $c): string => $c->id, $had));

                continue;
            }

            $stats['documents']++;
            $stats['chunks'] += count($pieces);

            foreach ($pieces as $position => $text) {
                $hash = hash('sha256', $text);
                $chunk = $had[$position] ?? null;
                unset($had[$position]);

                if ($chunk !== null && $chunk->text_hash === $hash) {
                    continue;
                }

                $values = [
                    'external_id' => $document->externalId,
                    'title' => mb_substr($document->title, 0, 500),
                    'text' => $text,
                    'text_hash' => $hash,
                    'embedding' => null,
                    'embedding_model' => null,
                    'dimensions' => null,
                    'embedded_at' => null,
                ];

                $chunk === null
                    ? RetrievalChunk::query()->create($values + ['shop_id' => $shopId, 'source' => $key, 'source_id' => $document->sourceId, 'position' => $position])
                    : RetrievalChunk::query()->whereKey($chunk->id)->update($values);

                $stats['changed']++;
            }

            // A document that got shorter.
            array_push($stale, ...array_map(fn (RetrievalChunk $c): string => $c->id, $had));
        }

        // Documents the source no longer has: a product the store stopped publishing.
        foreach ($existing as $chunks) {
            array_push($stale, ...array_map(fn (RetrievalChunk $c): string => $c->id, $chunks));
        }

        foreach (array_chunk($stale, 500) as $ids) {
            $stats['removed'] += RetrievalChunk::query()->whereKey($ids)->delete();
        }

        return $stats;
    }

    /** @return array{provider: string, model: string, dimensions: int|null, embedded: int, pending: int, stopped: string|null, cost_usd: float} */
    private function embed(RunContext $run, string $shopId): array
    {
        $choice = ModelChoice::for('embedding');
        $dimensions = (int) Settings::get('retrieval.embedding_dimensions') ?: null;
        $price = (float) Settings::get('retrieval.embedding_usd_per_million');
        $pending = fn () => RetrievalChunk::query()->where(fn ($q) => $q->whereNull('embedding_model')->orWhere('embedding_model', '!=', $choice->model));

        $result = ['provider' => $choice->providerName, 'model' => $choice->model, 'dimensions' => null, 'embedded' => 0, 'pending' => 0, 'stopped' => null, 'cost_usd' => 0.0];

        if ($choice->provider === null) {
            $result['stopped'] = 'unknown_provider';
        }

        $batches = $result['stopped'] === null
            ? $pending()->orderBy('source')->orderBy('id')->limit((int) Settings::get('retrieval.max_chunks_per_run', $shopId))->get(['id', 'text'])->chunk(self::BATCH)
            : collect();

        foreach ($batches as $batch) {
            $texts = $batch->pluck('text')->values()->all();
            $estimate = array_sum(array_map('mb_strlen', $texts)) / self::CHARS_PER_TOKEN * $price / 1_000_000;

            try {
                $this->spend->assertCanSpend($estimate);
                $embeddings = $this->embedder->embed($choice->provider, $choice->model, $texts, $dimensions);
            } catch (SpendCapReached) {
                $result['stopped'] = 'spend_cap';
                break;
            } catch (ModelCallFailed $e) {
                $result['stopped'] = $e->reason;
                break;
            }

            $cost = $embeddings->costUsd($price);
            $run->usage($choice->providerName, $choice->model, $embeddings->inputTokens, 0, 0, $cost);
            $result['cost_usd'] += $cost;
            $result['dimensions'] = $embeddings->dimensions();

            foreach ($batch->values() as $i => $chunk) {
                RetrievalChunk::query()->whereKey($chunk->id)->update([
                    'embedding' => VectorSearch::column($embeddings->vectors[$i]),
                    'embedding_model' => $choice->model,
                    'dimensions' => count($embeddings->vectors[$i]),
                    'embedded_at' => now(),
                ]);
            }

            $result['embedded'] += $batch->count();
        }

        $result['pending'] = $pending()->count();
        $result['cost_usd'] = round($result['cost_usd'], 6);

        return $result;
    }
}
