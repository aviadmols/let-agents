<?php

namespace App\Modules\Retrieval\Support;

use App\Core\Facades\Settings;
use App\Modules\Ai\Contracts\Embedder;
use App\Modules\Ai\Contracts\ImageEmbedder;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\SpendCapReached;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Retrieval\Contracts\EmbedderTrial;
use App\Modules\Retrieval\Contracts\EmbedderTrials;
use App\Modules\Retrieval\Contracts\SectionQuestions;
use App\Modules\Retrieval\Enums\ChunkSource;
use App\Modules\Retrieval\Models\RetrievalChunk;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Enums\RunTrigger;

/**
 * Another embedding model measured on the shop's own pieces, in memory only (see EmbedderTrials).
 *
 * The pieces are read in the order of their documents, embedded a batch at a time and compared with
 * every query at once; a document's best piece goes into each query's short list, and the vectors are
 * dropped. So a catalogue of tens of thousands of pieces needs only the queries' vectors and the short
 * lists in memory.
 */
final class InMemoryEmbedderTrials implements EmbedderTrials
{
    public const AGENT = 'retrieval.embedder_trial';

    private const BATCH = 100;

    /** Hebrew runs at about two characters a token; estimating high is the safe side. */
    private const CHARS_PER_TOKEN = 2;

    /** What the live search compares by meaning, and how its results name them. */
    private const PREFIX = [ChunkSource::Product->value => 'p:', ChunkSource::Content->value => 'c:'];

    public function __construct(
        private readonly Embedder $embedder,
        private readonly ImageEmbedder $imageEmbedder,
        private readonly SpendGuard $spend,
        private readonly RecordsRuns $runs,
    ) {}

    public function rank(string $shopId, array $queries, array $trials, int $limit, float $maxUsd): array
    {
        $queries = array_values(array_unique(array_filter(array_map('trim', $queries), fn (string $q): bool => $q !== '')));
        $out = [];

        foreach ($trials as $trial) {
            $result = ['ranked' => [], 'pieces' => 0, 'input_tokens' => 0, 'cost_usd' => 0.0, 'stopped' => null];

            $this->runs->track(self::AGENT, 'retrieval.embedder_trial', function (RunContext $run) use (&$result, $trial, $queries, $limit, $maxUsd): void {
                $result = $this->trial($run, $trial, $queries, max(1, $limit), $maxUsd);
                $run->output(['trial' => $trial->name, 'model' => $result['model'], 'queries' => count($queries)] + array_diff_key($result, ['ranked' => 1]))
                    ->summary('retrieval::runs.embedder_trial', ['model' => $result['model'], 'queries' => (string) count($queries), 'pieces' => (string) $result['pieces']]);
            }, $shopId, ['trial' => $trial->name, 'model' => $trial->model], RunTrigger::Manual);

            unset($result['model']);
            $out[$trial->name] = $result;
        }

        return $out;
    }

    public function size(string $shopId): array
    {
        $row = RetrievalChunk::query()->selectRaw('count(*) as pieces, coalesce(sum(length(text)), 0) as chars')->first();
        $writers = iterator_to_array(app()->tagged(SectionQuestions::TAG), false);
        $asks = 0;
        $askChars = 0;

        if ($writers !== []) {
            RetrievalChunk::query()->where('source', ChunkSource::Content->value)->select(['id', 'title', 'text_hash'])
                ->chunkById(500, function ($chunks) use ($writers, $shopId, &$asks, &$askChars): void {
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
                        if ($lines !== []) {
                            // the same text BuildIndex embeds: the section's title and its questions
                            $asks++;
                            $askChars += mb_strlen($chunk->title."\n".implode("\n", $lines));
                        }
                    }
                });
        }

        return ['pieces' => (int) ($row->pieces ?? 0), 'chars' => (int) ($row->chars ?? 0), 'asks' => $asks, 'ask_chars' => $askChars];
    }

    /**
     * @param  list<string>  $queries
     * @return array{model: string, ranked: array<string, list<string>>, pieces: int, input_tokens: int, cost_usd: float, stopped: string|null}
     */
    private function trial(RunContext $run, EmbedderTrial $trial, array $queries, int $limit, float $maxUsd): array
    {
        $index = ModelChoice::for('embedding');
        [$providerName, $model, $dimensions, $price] = $trial->index
            ? [$index->providerName, $index->model, (int) Settings::get('retrieval.embedding_dimensions') ?: null, (float) Settings::get('retrieval.embedding_usd_per_million')]
            : [strtolower(trim($trial->provider)), trim($trial->model), $trial->dimensions, $trial->usdPerMillion];
        $provider = AiProviderName::tryFrom($providerName);
        $result = ['model' => $providerName.' · '.$model, 'ranked' => [], 'pieces' => 0, 'input_tokens' => 0, 'cost_usd' => 0.0, 'stopped' => null];

        if ($provider === null || $model === '' || $index->model === '') {
            $result['stopped'] = 'unknown_provider';

            return $result;
        }

        // One call: SpendGuard first, this trial's own ceiling, the cost on the run.
        $embed = function (array $texts, bool $asQuery) use ($run, $trial, $provider, $providerName, $model, $dimensions, $price, $maxUsd, &$result): ?array {
            $estimate = array_sum(array_map('mb_strlen', $texts)) / self::CHARS_PER_TOKEN * $price / 1_000_000;
            if ($maxUsd < $result['cost_usd'] + $estimate) {
                $result['stopped'] = 'max_usd';

                return null;
            }
            try {
                $this->spend->assertCanSpend($estimate);
                $embeddings = $asQuery && $trial->asSearch
                    ? $this->imageEmbedder->embedTextsForImages($provider, $model, $texts, $dimensions, query: true)
                    : $this->embedder->embed($provider, $model, $texts, $dimensions);
            } catch (SpendCapReached) {
                $result['stopped'] = 'spend_cap';

                return null;
            } catch (ModelCallFailed $e) {
                $result['stopped'] = $e->reason;

                return null;
            }
            $cost = $embeddings->costUsd($price);
            $run->usage($providerName, $model, $embeddings->inputTokens, 0, 0, $cost);
            $result['cost_usd'] += $cost;
            $result['input_tokens'] += $embeddings->inputTokens;

            return array_map(fn (array $v): array => VectorSearch::normalize($v), $embeddings->vectors);
        };

        $asked = [];
        foreach (array_chunk($queries, self::BATCH) as $batch) {
            $vectors = $embed($batch, true);
            if ($vectors === null) {
                return $result;
            }
            array_push($asked, ...$vectors);
        }

        $top = array_fill(0, count($queries), []);
        $doc = null;
        $best = [];
        $flush = function () use (&$doc, &$best, &$top, $limit): void {
            if ($doc === null) {
                return;
            }
            foreach ($best as $q => $similarity) {
                $top[$q][$doc] = $similarity;
                if (count($top[$q]) > $limit * 3) {
                    arsort($top[$q]);
                    $top[$q] = array_slice($top[$q], 0, $limit, true);
                }
            }
            $best = [];
        };
        // A piece's similarity to every query; a document keeps its best piece for each.
        $score = function (string $key, array $vector) use (&$doc, &$best, $asked, $flush): void {
            if ($key !== $doc) {
                $flush();
                $doc = $key;
            }
            foreach ($asked as $q => $query) {
                $similarity = 0.0;
                foreach ($vector as $i => $x) {
                    $similarity += $x * ($query[$i] ?? 0.0);
                }
                if (! isset($best[$q]) || $similarity > $best[$q]) {
                    $best[$q] = $similarity;
                }
            }
        };

        // The same pieces for every trial: those the index holds a vector of its own model for.
        $pieces = RetrievalChunk::query()->whereIn('source', array_keys(self::PREFIX))->whereNotNull('external_id')
            ->where('embedding_model', $index->model)->whereNotNull('embedding')
            ->orderBy('source')->orderBy('source_id')->orderBy('position')->orderBy('id')
            ->select(['id', 'source', 'source_id', 'external_id', 'text', ...($trial->index ? ['embedding'] : [])]);
        $waiting = [];

        foreach ($pieces->lazy(500) as $chunk) {
            $key = self::PREFIX[$chunk->source].$chunk->external_id;
            $result['pieces']++;

            if ($trial->index) {
                $score($key, VectorSearch::normalize($chunk->vector() ?? []));

                continue;
            }
            $waiting[] = [$key, (string) $chunk->text];
            if (count($waiting) === self::BATCH) {
                $vectors = $embed(array_column($waiting, 1), false);
                if ($vectors === null) {
                    break;
                }
                foreach ($waiting as $i => [$waitingKey]) {
                    $score($waitingKey, $vectors[$i] ?? []);
                }
                $waiting = [];
            }
        }

        if ($waiting !== [] && $result['stopped'] === null && ($vectors = $embed(array_column($waiting, 1), false)) !== null) {
            foreach ($waiting as $i => [$waitingKey]) {
                $score($waitingKey, $vectors[$i] ?? []);
            }
        }
        // a trial that stopped part way saw only part of the shop: no ranking to compare
        if ($result['stopped'] !== null) {
            return $result;
        }
        $flush();

        foreach ($queries as $q => $text) {
            arsort($top[$q]);
            $result['ranked'][$text] = array_slice(array_keys($top[$q]), 0, $limit);
        }
        $result['cost_usd'] = round($result['cost_usd'], 6);

        return $result;
    }
}
