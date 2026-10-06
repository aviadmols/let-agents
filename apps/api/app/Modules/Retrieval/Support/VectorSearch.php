<?php

namespace App\Modules\Retrieval\Support;

use App\Modules\Retrieval\Contracts\SemanticSearch;
use App\Modules\Retrieval\Models\RetrievalChunk;
use App\Modules\Retrieval\Models\RetrievalImage;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

/**
 * Nearest pieces by cosine similarity, among vectors of the model the settings name now.
 *
 * On Postgres, pgvector does the comparing (`<=>` is cosine distance). Elsewhere (SQLite, locally
 * and in tests) the vectors are read and compared in PHP, which is the same arithmetic and fine
 * for the few thousand pieces a test or a laptop holds.
 *
 * A document is as near as its nearest piece, and is returned once.
 */
final class VectorSearch implements SemanticSearch
{
    /** Pieces read per document asked for, before keeping the best piece of each. */
    private const OVERFETCH = 4;

    public function similarTo(string $source, string $sourceId, array $sources, int $limit): array
    {
        $model = ModelChoice::for('embedding')->model;
        $seed = RetrievalChunk::query()
            ->where('source', $source)->where('source_id', $sourceId)
            ->where('embedding_model', $model)->whereNotNull('embedding')
            ->orderBy('position')
            ->first();

        $vector = $seed?->vector();

        if ($vector === null || $limit < 1) {
            return [];
        }

        return $this->nearest($vector, $model, $sources, $limit, $source, $sourceId);
    }

    public function nearText(string $shopId, string $text, array $sources, int $limit): array
    {
        if ($limit < 1) {
            return [];
        }

        $vector = app(QueryVectors::class)->for($shopId, $text);

        return $vector === null ? [] : $this->nearest($vector, ModelChoice::for('embedding')->model, $sources, $limit);
    }

    /**
     * @param  list<float>  $vector
     * @param  list<string>  $sources
     * @return list<array{source: string, source_id: string, external_id: string|null, title: string, similarity: float}>
     */
    private function nearest(array $vector, string $model, array $sources, int $limit, ?string $source = null, ?string $sourceId = null): array
    {
        $rows = DB::connection()->getDriverName() === 'pgsql'
            ? $this->nearestInPostgres($vector, $model, $sources, $source, $sourceId, $limit * self::OVERFETCH)
            : $this->nearestInPhp($vector, $model, $sources, $source, $sourceId);

        $best = [];

        foreach ($rows as $row) {
            $key = $row['source'].'|'.$row['source_id'];

            if (! isset($best[$key]) || $row['similarity'] > $best[$key]['similarity']) {
                $best[$key] = $row;
            }
        }

        usort($best, fn (array $a, array $b): int => $b['similarity'] <=> $a['similarity']);

        return array_slice(array_values($best), 0, $limit);
    }

    public function lookAlike(string $productId, int $limit): array
    {
        $model = ModelChoice::for('image')->model;
        $vector = RetrievalImage::query()->where('product_id', $productId)->where('embedding_model', $model)->first()?->vector();

        return $vector === null || $limit < 1 ? [] : $this->nearestPictures($vector, $model, $limit, $productId);
    }

    public function picturesNearText(string $shopId, string $text, int $limit): array
    {
        if ($limit < 1 || ! $this->picturesReady()) {
            return [];
        }

        $vector = app(QueryVectors::class)->for($shopId, $text, 'image');

        return $vector === null ? [] : $this->nearestPictures($vector, ModelChoice::for('image')->model, $limit);
    }

    public function picturesReady(): bool
    {
        return RetrievalImage::query()->where('embedding_model', ModelChoice::for('image')->model)->whereNotNull('embedding')->exists();
    }

    public function ready(): bool
    {
        return RetrievalChunk::query()
            ->where('embedding_model', ModelChoice::for('embedding')->model)
            ->whereNotNull('embedding')
            ->exists();
    }

    /** @return list<float> */
    public static function normalize(array $vector): array
    {
        $norm = sqrt(array_sum(array_map(fn (float $v): float => $v * $v, $vector)));

        return $norm > 0 ? array_map(fn (float $v): float => $v / $norm, $vector) : $vector;
    }

    /**
     * The value to store in the embedding column: pgvector's literal, cast, on Postgres; the same
     * text on SQLite. Safe to inline, because the literal is built only from formatted floats.
     *
     * @param  list<float>  $vector
     */
    public static function column(array $vector): Expression|string
    {
        $literal = self::literal($vector);

        return DB::connection()->getDriverName() === 'pgsql' ? DB::raw("'{$literal}'::vector") : $literal;
    }

    /** @param list<float> $vector */
    public static function literal(array $vector): string
    {
        return '['.implode(',', array_map(fn (float $v): string => rtrim(rtrim(sprintf('%.7F', $v), '0'), '.') ?: '0', $vector)).']';
    }

    /**
     * @param  list<float>  $vector
     * @param  list<string>  $sources
     * @return list<array{source: string, source_id: string, external_id: string|null, title: string, similarity: float}>
     */
    private function nearestInPostgres(array $vector, string $model, array $sources, ?string $source, ?string $sourceId, int $take): array
    {
        $literal = self::literal($vector);

        return RetrievalChunk::query()
            ->select(['source', 'source_id', 'external_id', 'title'])
            ->selectRaw('1 - (embedding <=> ?::vector) as similarity', [$literal])
            ->where('embedding_model', $model)
            ->whereNotNull('embedding')
            ->whereIn('source', $sources)
            ->when($source !== null, fn ($query) => $query->where(fn ($q) => $q->where('source', '!=', $source)->orWhere('source_id', '!=', $sourceId)))
            ->orderByRaw('embedding <=> ?::vector', [$literal])
            ->limit($take)
            ->get()
            ->map(fn (RetrievalChunk $c): array => [
                'source' => $c->source,
                'source_id' => $c->source_id,
                'external_id' => $c->external_id,
                'title' => $c->title,
                'similarity' => round((float) $c->getAttribute('similarity'), 4),
            ])
            ->all();
    }

    /**
     * @param  list<float>  $vector
     * @param  list<string>  $sources
     * @return list<array{source: string, source_id: string, external_id: string|null, title: string, similarity: float}>
     */
    private function nearestInPhp(array $vector, string $model, array $sources, ?string $source, ?string $sourceId): array
    {
        $seed = self::normalize($vector);
        $rows = [];

        RetrievalChunk::query()
            ->where('embedding_model', $model)
            ->whereNotNull('embedding')
            ->whereIn('source', $sources)
            ->select(['id', 'source', 'source_id', 'external_id', 'title', 'embedding'])
            ->chunkById(500, function ($chunks) use (&$rows, $seed, $source, $sourceId): void {
                foreach ($chunks as $chunk) {
                    if ($chunk->source === $source && $chunk->source_id === $sourceId) {
                        continue;
                    }

                    $other = $chunk->vector();

                    if ($other === null || count($other) !== count($seed)) {
                        continue;
                    }

                    $other = self::normalize($other);
                    $dot = 0.0;

                    foreach ($seed as $i => $value) {
                        $dot += $value * $other[$i];
                    }

                    $rows[] = [
                        'source' => $chunk->source,
                        'source_id' => $chunk->source_id,
                        'external_id' => $chunk->external_id,
                        'title' => $chunk->title,
                        'similarity' => round($dot, 4),
                    ];
                }
            });

        return $rows;
    }

    /**
     * @param  list<float>  $vector
     * @return list<array{product_id: string, external_id: string, title: string, similarity: float}>
     */
    private function nearestPictures(array $vector, string $model, int $limit, ?string $exceptProductId = null): array
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            $literal = self::literal($vector);

            return RetrievalImage::query()
                ->select(['product_id', 'external_id', 'title'])
                ->selectRaw('1 - (embedding <=> ?::vector) as similarity', [$literal])
                ->where('embedding_model', $model)->whereNotNull('embedding')
                ->when($exceptProductId !== null, fn ($q) => $q->where('product_id', '!=', $exceptProductId))
                ->orderByRaw('embedding <=> ?::vector', [$literal])
                ->limit($limit)
                ->get()
                ->map(fn (RetrievalImage $i): array => [
                    'product_id' => $i->product_id,
                    'external_id' => $i->external_id,
                    'title' => $i->title,
                    'similarity' => round((float) $i->getAttribute('similarity'), 4),
                ])
                ->all();
        }

        $seed = self::normalize($vector);
        $rows = [];

        RetrievalImage::query()
            ->where('embedding_model', $model)->whereNotNull('embedding')
            ->when($exceptProductId !== null, fn ($q) => $q->where('product_id', '!=', $exceptProductId))
            ->select(['id', 'product_id', 'external_id', 'title', 'embedding'])
            ->chunkById(500, function ($images) use (&$rows, $seed): void {
                foreach ($images as $image) {
                    $other = $image->vector();

                    if ($other === null || count($other) !== count($seed)) {
                        continue;
                    }

                    $other = self::normalize($other);
                    $dot = 0.0;

                    foreach ($seed as $i => $value) {
                        $dot += $value * $other[$i];
                    }

                    $rows[] = ['product_id' => $image->product_id, 'external_id' => $image->external_id, 'title' => $image->title, 'similarity' => round($dot, 4)];
                }
            });

        usort($rows, fn (array $a, array $b): int => $b['similarity'] <=> $a['similarity']);

        return array_slice($rows, 0, $limit);
    }
}
