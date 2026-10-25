<?php

namespace App\Modules\Retrieval\Support;

use App\Core\Facades\Settings;
use App\Modules\Ai\Contracts\Embedder;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\SpendCapReached;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Retrieval\Contracts\TermVectors;
use App\Modules\Retrieval\Models\RetrievalQueryVector;
use App\Modules\Runs\Models\Run;
use Illuminate\Support\Facades\DB;

/**
 * The shop's words as vectors, kept with the shoppers' (retrieval_query_vectors) under "term:<model>": embedded plain,
 * as the pages are, in batches, each once. A typed word is a query (QueryVectors), compared with them by cosine.
 */
final class StoredTermVectors implements TermVectors
{
    /** Terms embedded in one call. */
    private const BATCH = 100;

    public function __construct(
        private readonly Embedder $embedder,
        private readonly SpendGuard $spend,
        private readonly QueryVectors $queries,
    ) {}

    public function remember(string $shopId, array $terms): int
    {
        $choice = ModelChoice::for('embedding', $shopId);
        if ($choice->provider === null || $choice->model === '' || $terms === []) {
            return 0;
        }
        $stored = 'term:'.$choice->model;
        $byHash = [];
        foreach ($terms as $term) {
            $term = mb_substr(trim((string) $term), 0, 60);
            if ($term !== '') {
                $byHash[hash('sha256', $term)] = $term;
            }
        }
        $known = array_flip(RetrievalQueryVector::query()->where('embedding_model', $stored)->whereIn('text_hash', array_keys($byHash))->pluck('text_hash')->all());
        $missing = array_diff_key($byHash, $known);
        $price = (float) Settings::get('retrieval.embedding_usd_per_million', $shopId);
        $dimensions = (int) Settings::get('retrieval.embedding_dimensions', $shopId) ?: null;
        $made = 0;

        foreach (array_chunk($missing, self::BATCH, true) as $batch) {
            try {
                $this->spend->assertCanSpend(forShopper: false, estimatedUsd: array_sum(array_map('mb_strlen', $batch)) * $price / 1_000_000);
                $embeddings = $this->embedder->embed($choice->provider, $choice->model, array_values($batch), $dimensions);
            } catch (SpendCapReached|ModelCallFailed) {
                break;
            }
            Run::recordUsage($shopId, QueryVectors::AGENT, QueryVectors::ACTION, $choice->providerName, $choice->model, $embeddings->inputTokens, 0, $embeddings->costUsd($price));
            foreach (array_values($batch) as $at => $term) {
                $vector = $embeddings->vectors[$at] ?? null;
                if ($vector === null || $vector === []) {
                    continue;
                }
                RetrievalQueryVector::query()->create([
                    'shop_id' => $shopId,
                    'embedding_model' => $stored,
                    'text_hash' => hash('sha256', $term),
                    'text' => $term,
                    'dimensions' => count($vector),
                    'embedding' => VectorSearch::column($vector),
                ]);
                $made++;
            }
        }

        return $made;
    }

    public function nearest(string $shopId, string $word, int $limit, float $floor): array
    {
        $choice = ModelChoice::for('embedding', $shopId);
        if ($choice->provider === null || $choice->model === '' || trim($word) === '' || $limit < 1) {
            return [];
        }
        $stored = 'term:'.$choice->model;
        if (! RetrievalQueryVector::query()->where('embedding_model', $stored)->exists()) {
            return [];
        }
        $vector = $this->queries->for($shopId, $word);
        if ($vector === null) {
            return [];
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            $literal = VectorSearch::literal($vector);
            $rows = RetrievalQueryVector::query()
                ->select(['text'])
                ->selectRaw('1 - (embedding <=> ?::vector) as similarity', [$literal])
                ->where('embedding_model', $stored)->where('dimensions', count($vector))
                ->orderByRaw('embedding <=> ?::vector', [$literal])
                ->limit($limit)
                ->get()
                ->map(fn ($r): array => ['term' => (string) $r->text, 'similarity' => round((float) $r->getAttribute('similarity'), 4)])
                ->all();
        } else {
            $seed = self::unit($vector);
            $rows = [];
            foreach (RetrievalQueryVector::query()->where('embedding_model', $stored)->get(['text', 'embedding']) as $row) {
                $other = $row->vector();
                if ($other === null || count($other) !== count($seed)) {
                    continue;
                }
                $other = self::unit($other);
                $dot = 0.0;
                foreach ($seed as $i => $v) {
                    $dot += $v * $other[$i];
                }
                $rows[] = ['term' => (string) $row->text, 'similarity' => round($dot, 4)];
            }
            usort($rows, fn (array $a, array $b): int => $b['similarity'] <=> $a['similarity']);
            $rows = array_slice($rows, 0, $limit);
        }

        return array_values(array_filter($rows, fn (array $r): bool => $r['similarity'] >= $floor));
    }

    /**
     * @param  list<float>  $vector
     * @return list<float>
     */
    private static function unit(array $vector): array
    {
        $norm = sqrt(array_sum(array_map(fn (float $v): float => $v * $v, $vector)));

        return $norm == 0.0 ? $vector : array_map(fn (float $v): float => $v / $norm, $vector);
    }
}
