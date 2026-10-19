<?php

namespace App\Modules\Retrieval\Support;

use App\Modules\Retrieval\Contracts\Passages;
use App\Modules\Retrieval\Enums\ChunkSource;
use App\Modules\Retrieval\Models\RetrievalChunk;
use Illuminate\Support\Facades\DB;

/**
 * The nearest pieces of text by meaning, read from the same index nearText reads. A section of a
 * page or post is as near as the closer of its text and the questions it answers (ask_embedding);
 * one about the question's topic (Topics: returns, shipping...) is a little nearer, and is
 * considered even when its words are far from the question's, so "אפשר להחזיר?" finds the return
 * policy however the store titled it.
 */
final class PassageSearch implements Passages
{
    private const OVERFETCH = 4;

    private const PER_DOCUMENT = 2;

    /** How much nearer a section of the question's topic is. */
    private const TOPIC_BOOST = 0.05;

    /** Sections of the question's topic considered beyond the nearest. */
    private const TOPIC_RECALL = 30;

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
        $topics = in_array(ChunkSource::Content->value, $sources, true) ? Topics::ofQuestion($text) : [];
        $rows = DB::connection()->getDriverName() === 'pgsql'
            ? $this->inPostgres($vector, $model, $sources, $limit * self::OVERFETCH, $topics)
            : $this->inPhp($vector, $model, $sources);

        $rows = array_map(function (array $row) use ($topics): array {
            if ($topics !== [] && array_intersect($topics, $row['topics']) !== []) {
                $row['similarity'] = round(min(1.0, $row['similarity'] + self::TOPIC_BOOST), 4);
            }
            unset($row['topics']);

            return $row;
        }, $rows);

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
     * The nearest pieces, and the sections of the question's topics however far.
     *
     * @param  list<float>  $vector
     * @param  list<string>  $sources
     * @param  list<string>  $topics
     * @return list<array<string, mixed>>
     */
    private function inPostgres(array $vector, string $model, array $sources, int $take, array $topics): array
    {
        $literal = VectorSearch::literal($vector);
        $nearness = 'GREATEST(1 - (embedding <=> ?::vector), COALESCE(1 - (ask_embedding <=> ?::vector), 0))';
        $query = fn () => RetrievalChunk::query()
            ->select(['id', 'source', 'source_id', 'external_id', 'title', 'text', 'topics'])
            ->selectRaw($nearness.' as similarity', [$literal, $literal])
            ->where('embedding_model', $model)
            ->whereNotNull('embedding');

        $rows = $query()->whereIn('source', $sources)->orderByRaw($nearness.' desc', [$literal, $literal])->limit($take)->get();

        if ($topics !== []) {
            $about = $query()->where('source', ChunkSource::Content->value)
                ->where(function ($q) use ($topics): void {
                    foreach ($topics as $topic) {
                        $q->orWhere('topics', 'like', '% '.$topic.' %');
                    }
                })
                ->orderByRaw($nearness.' desc', [$literal, $literal])->limit(self::TOPIC_RECALL)->get();
            $rows = $rows->merge($about)->unique('id');
        }

        return $rows->map(fn (RetrievalChunk $c): array => self::row($c, (float) $c->getAttribute('similarity')))->values()->all();
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
        $dot = function (?array $other) use ($seed): float {
            if ($other === null || count($other) !== count($seed)) {
                return 0.0;
            }
            $other = VectorSearch::normalize($other);
            $sum = 0.0;
            foreach ($seed as $i => $value) {
                $sum += $value * $other[$i];
            }

            return $sum;
        };

        RetrievalChunk::query()
            ->where('embedding_model', $model)
            ->whereNotNull('embedding')
            ->whereIn('source', $sources)
            ->select(['id', 'source', 'source_id', 'external_id', 'title', 'text', 'topics', 'embedding', 'ask_embedding'])
            ->chunkById(500, function ($chunks) use (&$rows, $seed, $dot): void {
                foreach ($chunks as $chunk) {
                    $other = $chunk->vector();

                    if ($other === null || count($other) !== count($seed)) {
                        continue;
                    }

                    $rows[] = self::row($chunk, max($dot($other), $dot($chunk->askVector())));
                }
            });

        return $rows;
    }

    /** @return array{source: string, source_id: string, external_id: string|null, title: string, text: string, similarity: float, topics: list<string>} */
    private static function row(RetrievalChunk $chunk, float $similarity): array
    {
        return [
            'source' => $chunk->source,
            'source_id' => $chunk->source_id,
            'external_id' => $chunk->external_id,
            'title' => $chunk->title,
            'text' => (string) $chunk->text,
            'similarity' => round($similarity, 4),
            'topics' => Topics::fromColumn($chunk->topics),
        ];
    }
}
