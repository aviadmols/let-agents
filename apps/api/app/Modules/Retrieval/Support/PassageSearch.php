<?php

namespace App\Modules\Retrieval\Support;

use App\Modules\Retrieval\Contracts\Passages;
use App\Modules\Retrieval\Models\RetrievalChunk;
use Illuminate\Support\Facades\DB;

/** The nearest pieces of text by meaning, read from the same index nearText reads. */
final class PassageSearch implements Passages
{
    private const OVERFETCH = 4;

    private const PER_DOCUMENT = 2;

    public function near(string $shopId, string $text, array $sources, int $limit): array
    {
        if ($limit < 1 || $sources === []) {
            return [];
        }

        $vector = app(QueryVectors::class)->for($shopId, $text);

        if ($vector === null) {
            return [];
        }

        $model = ModelChoice::for('embedding')->model;
        $rows = DB::connection()->getDriverName() === 'pgsql'
            ? $this->inPostgres($vector, $model, $sources, $limit * self::OVERFETCH)
            : $this->inPhp($vector, $model, $sources);

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

    /**
     * @param  list<float>  $vector
     * @param  list<string>  $sources
     * @return list<array<string, mixed>>
     */
    private function inPostgres(array $vector, string $model, array $sources, int $take): array
    {
        $literal = VectorSearch::literal($vector);

        return RetrievalChunk::query()
            ->select(['source', 'source_id', 'external_id', 'title', 'text'])
            ->selectRaw('1 - (embedding <=> ?::vector) as similarity', [$literal])
            ->where('embedding_model', $model)
            ->whereNotNull('embedding')
            ->whereIn('source', $sources)
            ->orderByRaw('embedding <=> ?::vector', [$literal])
            ->limit($take)
            ->get()
            ->map(fn (RetrievalChunk $c): array => self::row($c, (float) $c->getAttribute('similarity')))
            ->all();
    }

    /**
     * @param  list<float>  $vector
     * @param  list<string>  $sources
     * @return list<array<string, mixed>>
     */
    private function inPhp(array $vector, string $model, array $sources): array
    {
        $seed = VectorSearch::normalize($vector);
        $rows = [];

        RetrievalChunk::query()
            ->where('embedding_model', $model)
            ->whereNotNull('embedding')
            ->whereIn('source', $sources)
            ->select(['id', 'source', 'source_id', 'external_id', 'title', 'text', 'embedding'])
            ->chunkById(500, function ($chunks) use (&$rows, $seed): void {
                foreach ($chunks as $chunk) {
                    $other = $chunk->vector();

                    if ($other === null || count($other) !== count($seed)) {
                        continue;
                    }

                    $other = VectorSearch::normalize($other);
                    $dot = 0.0;

                    foreach ($seed as $i => $value) {
                        $dot += $value * $other[$i];
                    }

                    $rows[] = self::row($chunk, $dot);
                }
            });

        return $rows;
    }

    /** @return array{source: string, source_id: string, external_id: string|null, title: string, text: string, similarity: float} */
    private static function row(RetrievalChunk $chunk, float $similarity): array
    {
        return [
            'source' => $chunk->source,
            'source_id' => $chunk->source_id,
            'external_id' => $chunk->external_id,
            'title' => $chunk->title,
            'text' => (string) $chunk->text,
            'similarity' => round($similarity, 4),
        ];
    }
}
