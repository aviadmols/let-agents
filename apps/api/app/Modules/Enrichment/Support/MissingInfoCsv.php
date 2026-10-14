<?php

namespace App\Modules\Enrichment\Support;

use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Actions\ApplyTeamValue;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;

/**
 * The missing details as a spreadsheet the team fills in and uploads back: one row per product and
 * detail. A value is the choice's name (or its key) or a number; "-" or "לא רלוונטי" says the detail
 * does not apply. A row left empty is left alone. Inside the shop's tenant.
 */
final class MissingInfoCsv
{
    public const HEADER = ['product_id', 'title', 'type', 'attribute_key', 'attribute', 'importance', 'value'];

    /** Words that say "does not apply". */
    private const NOT_APPLICABLE = ['-', '—', 'לא רלוונטי', 'n/a', 'na', 'not applicable'];

    /**
     * @param  list<array<string, mixed>>  $gaps  RequiredGaps::of()
     */
    public static function export(array $gaps): string
    {
        $out = fopen('php://memory', 'w+');
        fwrite($out, "\xEF\xBB\xBF"); // Excel reads Hebrew only with this
        fputcsv($out, self::HEADER);

        foreach ($gaps as $gap) {
            foreach ($gap['missing'] as $product) {
                fputcsv($out, [$product['external_id'], $product['title'], $gap['type_label'], $gap['attribute'], $gap['attribute_label'], $gap['importance'], '']);
            }
        }

        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /** @return array{applied: int, not_applicable: int, problems: list<array{line: int, product: string, attribute: string, problem: string}>} */
    public static function import(string $csv, ApplyTeamValue $apply): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        $lines = preg_split('/\r\n|\r|\n/', trim($csv)) ?: [];
        $header = str_getcsv((string) array_shift($lines), ',', '"', '');
        $at = array_flip(array_map('trim', $header));
        $result = ['applied' => 0, 'not_applicable' => 0, 'problems' => []];

        foreach (['product_id', 'attribute_key', 'value'] as $needed) {
            if (! isset($at[$needed])) {
                $result['problems'][] = ['line' => 1, 'product' => '', 'attribute' => '', 'problem' => 'bad_header'];

                return $result;
            }
        }

        foreach ($lines as $i => $line) {
            if (trim($line) === '') {
                continue;
            }

            $row = str_getcsv($line, ',', '"', '');
            $value = trim((string) ($row[$at['value']] ?? ''));

            if ($value === '') {
                continue;
            }

            $externalId = trim((string) ($row[$at['product_id']] ?? ''));
            $attribute = trim((string) ($row[$at['attribute_key']] ?? ''));
            $fail = fn (string $problem): array => ['line' => $i + 2, 'product' => $externalId, 'attribute' => $attribute, 'problem' => $problem];

            $product = CatalogProduct::query()->where('external_id', $externalId)->first();
            $vocabularyId = $product === null ? null : EnrichmentFact::query()->where('product_id', $product->id)->where('kind', FactKind::Type)
                ->where('status', FactStatus::Approved)->orderBy('id')->value('vocabulary_id');

            if ($product === null || $vocabularyId === null) {
                $result['problems'][] = $fail('unknown_product');

                continue;
            }

            if (in_array(mb_strtolower($value), self::NOT_APPLICABLE, true)) {
                $done = $apply->notApplicable($product->id, $vocabularyId, $attribute);
                $done['ok'] ? $result['not_applicable']++ : $result['problems'][] = $fail((string) $done['problem']);

                continue;
            }

            $done = $apply->set($product->id, $vocabularyId, $attribute, self::choice($vocabularyId, $attribute, $value));
            $done['ok'] ? $result['applied']++ : $result['problems'][] = $fail((string) $done['problem']);
        }

        return $result;
    }

    /**
     * A choice written by its name, in either language, is its key. Several are separated by a slash
     * or a semicolon. A number, or a key already, is left as it was.
     *
     * @return string|list<string>
     */
    private static function choice(string $vocabularyId, string $attribute, string $value): string|array
    {
        $entry = EnrichmentVocabulary::query()->find($vocabularyId)?->definition()->attribute($attribute);

        if (($entry['type'] ?? null) !== 'enum') {
            return $value;
        }

        $keys = [];
        foreach (preg_split('/\s*[;\/]\s*/u', $value) ?: [] as $part) {
            $found = $part;
            foreach ((array) ($entry['values'] ?? []) as $option) {
                $names = array_map('mb_strtolower', array_filter([(string) $option['key'], (string) ($option['label']['he'] ?? ''), (string) ($option['label']['en'] ?? '')]));
                if (in_array(mb_strtolower($part), $names, true)) {
                    $found = (string) $option['key'];
                    break;
                }
            }
            $keys[] = $found;
        }

        return count($keys) === 1 ? $keys[0] : $keys;
    }
}
