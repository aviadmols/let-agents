<?php

namespace ImageCaptionBenchmark;

use App\Modules\Search\Support\HebrewSearch;

/**
 * The same hybrid search over each flow's database, so the only difference between their answers
 * is what each flow wrote: the price and the attributes in the words become filters, then spelling
 * (production's HebrewSearch), meaning and the picture order what passes.
 */
final class Search
{
    private const TEXT_WEIGHTS = ['spelling' => 0.4, 'meaning' => 0.4, 'looks' => 0.2];

    private const PHOTO_WEIGHTS = ['photo' => 0.6, 'meaning' => 0.25, 'spelling' => 0.15];

    private const FILTERED = ['type' => 'product_type', 'material' => 'materials', 'stone' => 'stones', 'color' => 'colors', 'shape' => 'shapes', 'style' => 'styles'];

    /** What a query asks for, read once for every database. @return array<string, mixed> */
    public static function parse(string $raw): array
    {
        [$words, $min, $max] = HebrewSearch::priceRange($raw);
        $filters = array_filter(Vocabulary::read($words, array_keys(self::FILTERED)));
        $size = preg_match('/(?:מידה|size)\s*(\d+(?:[.,]\d+)?)/iu', $words, $m) === 1 ? str_replace(',', '.', $m[1]) : null;

        return ['words' => $words, 'min' => $min, 'max' => $max, 'filters' => $filters, 'size' => $size];
    }

    /**
     * @param  array{text?: list<float>|null, text_for_pictures?: list<float>|null, photo?: list<float>|null}  $vectors  the query's, embedded once
     * @return array{results: list<array<string, mixed>>, relaxed: bool, candidates: int}
     */
    public static function run(Store $store, array $parsed, array $vectors, int $top): array
    {
        $rows = array_filter($store->products(), fn (array $r): bool => self::inPrice($r, $parsed['min'], $parsed['max']));
        $matching = array_filter($rows, fn (array $r): bool => self::matches($r, $parsed['filters'], $parsed['size']));
        $relaxed = $matching === [] && ($parsed['filters'] !== [] || $parsed['size'] !== null);
        $candidates = $relaxed ? $rows : $matching;

        $spelling = [];
        if (trim($parsed['words']) !== '' && $candidates !== []) {
            $index = HebrewSearch::build(array_map(fn (array $r): array => ['id' => $r['id'], 'title' => $r['title'], 'keywords' => $r['search_text']], array_values($candidates)));
            $hits = HebrewSearch::search($index, $parsed['words']);
            $best = $hits[0]['score'] ?? 1.0;
            foreach ($hits as $hit) {
                $spelling[$hit['id']] = $best > 0 ? $hit['score'] / $best : 0.0;
            }
        }

        $combined = ($vectors['text'] ?? null) !== null || ($vectors['photo'] ?? null) !== null ? $store->vectors('combined') : [];
        $pictures = ($vectors['text_for_pictures'] ?? null) !== null || ($vectors['photo'] ?? null) !== null ? $store->vectors('image') : [];
        $photo = ($vectors['photo'] ?? null) !== null;
        $weights = $photo ? self::PHOTO_WEIGHTS : self::TEXT_WEIGHTS;

        $results = [];
        foreach ($candidates as $row) {
            $id = $row['id'];
            $parts = [
                'spelling' => $spelling[$id] ?? 0.0,
                'meaning' => isset($vectors['text'], $combined[$id]) ? self::cosine($vectors['text'], $combined[$id]) : null,
                'looks' => isset($vectors['text_for_pictures'], $pictures[$id]) ? self::cosine($vectors['text_for_pictures'], $pictures[$id]) : null,
                'photo' => $photo && isset($pictures[$id]) ? self::cosine($vectors['photo'], $pictures[$id]) : null,
            ];

            // Only the signals this query has: without keys a search is by spelling alone.
            $used = array_filter($weights, fn (string $part): bool => $parts[$part] !== null && ($part !== 'spelling' || trim($parsed['words']) !== ''), ARRAY_FILTER_USE_KEY);
            $total = array_sum($used);
            $score = $total > 0 ? array_sum(array_map(fn (string $part): float => $used[$part] * $parts[$part], array_keys($used))) / $total : 0.0;

            if ($score <= 0 && ! $photo) {
                continue;
            }

            $meta = json_decode($row['meta'], true);
            $results[] = [
                'id' => $id,
                'title' => $row['title'],
                'price' => $meta['commerce']['price'],
                'url' => $row['url'],
                'image_url' => $row['image_url'],
                'score' => round($score, 4),
                'parts' => array_map(fn (?float $v): ?float => $v === null ? null : round($v, 3), $parts),
                'filters' => array_intersect_key($meta['search']['filterable_fields'], array_flip(['product_type', 'product_type_source', 'color', 'color_source', 'material', 'material_source', 'stone', 'stone_source'])),
            ];
        }

        usort($results, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return ['results' => array_slice($results, 0, $top), 'relaxed' => $relaxed, 'candidates' => count($candidates)];
    }

    private static function inPrice(array $row, ?float $min, ?float $max): bool
    {
        if ($min === null && $max === null) {
            return true;
        }

        return $row['price_min'] !== null && ($min === null || $row['price_max'] >= $min) && ($max === null || $row['price_min'] <= $max);
    }

    private static function matches(array $row, array $filters, ?string $size): bool
    {
        foreach ($filters as $group => $values) {
            $column = self::FILTERED[$group];
            $held = $column === 'product_type' ? array_filter([(string) $row['product_type']]) : array_filter(explode('|', (string) $row[$column]));

            if (! array_filter($values, fn (string $v): bool => array_filter($held, fn (string $h): bool => Vocabulary::covers($v, $h)) !== [])) {
                return false;
            }
        }

        return $size === null || str_contains((string) $row['sizes'], '|'.$size.'|');
    }

    /** @param list<float> $a @param list<float> $b */
    private static function cosine(array $a, array $b): float
    {
        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;

        foreach ($a as $i => $x) {
            $y = $b[$i] ?? 0.0;
            $dot += $x * $y;
            $na += $x * $x;
            $nb += $y * $y;
        }

        return $na > 0 && $nb > 0 ? $dot / sqrt($na * $nb) : 0.0;
    }
}
