<?php

namespace App\Modules\Retrieval\Actions;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\Embedder;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\SpendCapReached;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Retrieval\Contracts\DocumentSource;
use App\Modules\Retrieval\Contracts\SectionQuestions;
use App\Modules\Retrieval\Enums\ChunkSource;
use App\Modules\Retrieval\Models\RetrievalChunk;
use App\Modules\Retrieval\Support\Chunker;
use App\Modules\Retrieval\Support\ModelChoice;
use App\Modules\Retrieval\Support\Topics;
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

        foreach (RetrievalChunk::query()->where('source', $key)->get(['id', 'source_id', 'position', 'text_hash', 'topics']) as $chunk) {
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
                // What a section of a page or post is about (returns, shipping...), in code: a question
                // of that topic finds it however it is worded (PassageSearch).
                $topics = $key === ChunkSource::Content->value ? Topics::column(Topics::ofSection($document->title, $text)) : null;

                if ($chunk !== null && $chunk->text_hash === $hash) {
                    if ($chunk->topics !== $topics) {
                        RetrievalChunk::query()->whereKey($chunk->id)->update(['topics' => $topics]);
                    }

                    continue;
                }

                $values = [
                    'external_id' => $document->externalId,
                    'title' => mb_substr($document->title, 0, 500),
                    'text' => $text,
                    'text_hash' => $hash,
                    'topics' => $topics,
                    'embedding' => null,
                    'embedding_model' => null,
                    'dimensions' => null,
                    'embedded_at' => null,
                    'ask_embedding' => null,
                    'asks_hash' => null,
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
                    // A vector of another model is never compared with this one: its questions are embedded again.
                    'ask_embedding' => null,
                    'asks_hash' => null,
                ]);
            }

            $result['embedded'] += $batch->count();
        }

        $result['pending'] = $pending()->count();
        $this->asks($run, $shopId, $choice, $dimensions, $price, $result);
        $result['cost_usd'] = round($result['cost_usd'], 6);

        return $result;
    }

    /**
     * The second vector of each section of a page or post: the questions it answers, as another
     * module wrote them (SectionQuestions), with its title. Embedded once for a model and the same
     * questions (asks_hash); a section whose questions are gone loses it. Within what is left of
     * the run's cap, after the sections' own text.
     *
     * @param  array<string, mixed>  $result
     */
    private function asks(RunContext $run, string $shopId, ModelChoice $choice, ?int $dimensions, float $price, array &$result): void
    {
        $writers = iterator_to_array(app()->tagged(SectionQuestions::TAG), false);
        $result['asked'] = 0;

        if ($writers === [] || $result['stopped'] !== null) {
            return;
        }

        $todo = [];
        $gone = [];

        RetrievalChunk::query()->where('source', ChunkSource::Content->value)->where('embedding_model', $choice->model)
            ->select(['id', 'title', 'text_hash', 'asks_hash'])
            ->chunkById(500, function ($chunks) use ($writers, $shopId, $choice, &$todo, &$gone): void {
                $hashes = $chunks->pluck('text_hash')->unique()->sort()->values()->all();
                $questions = [];
                foreach ($writers as $writer) {
                    /** @var SectionQuestions $writer */
                    foreach ($writer->questions($shopId, $hashes) as $hash => $lines) {
                        $questions[$hash] = [...($questions[$hash] ?? []), ...$lines];
                    }
                }

                foreach ($chunks as $chunk) {
                    $lines = $questions[$chunk->text_hash] ?? [];
                    if ($lines === []) {
                        if ($chunk->asks_hash !== null) {
                            $gone[] = $chunk->id;
                        }

                        continue;
                    }
                    $text = $chunk->title."\n".implode("\n", $lines);
                    $hash = hash('sha256', $choice->model."\n".$text);
                    if ($chunk->asks_hash !== $hash) {
                        $todo[] = [$chunk->id, $text, $hash];
                    }
                }
            });

        foreach (array_chunk($gone, 500) as $ids) {
            RetrievalChunk::query()->whereKey($ids)->update(['ask_embedding' => null, 'asks_hash' => null]);
        }

        $room = max(0, (int) Settings::get('retrieval.max_chunks_per_run', $shopId) - (int) $result['embedded']);

        foreach (array_chunk(array_slice($todo, 0, $room), self::BATCH) as $batch) {
            $texts = array_column($batch, 1);
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

            foreach ($batch as $i => [$id, , $hash]) {
                RetrievalChunk::query()->whereKey($id)->update(['ask_embedding' => VectorSearch::column($embeddings->vectors[$i]), 'asks_hash' => $hash]);
            }

            $result['asked'] += count($batch);
        }

        $result['pending'] += count($todo) - $result['asked'];
    }
}
