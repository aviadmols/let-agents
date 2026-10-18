<?php

namespace App\Modules\Enrichment\Support;

use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Retrieval\Contracts\DocumentNotes;
use App\Modules\Retrieval\Enums\ChunkSource;

/**
 * What the scan learned and the team approved, for the meaning vectors: a product's type and use
 * ("what it is and what it is for", so a look-alike with another use is not near it), its details
 * and tags, its highlights; a post's takeaways. Brand and category are left out (the product's own
 * text already says them). Sorted, so the same facts give the same text and nothing is embedded twice.
 */
final class FactNotes implements DocumentNotes
{
    private const PRODUCT_KINDS = [FactKind::Type, FactKind::Use, FactKind::Tag, FactKind::Spec, FactKind::Choice, FactKind::Flag, FactKind::Highlight];

    private const CONTENT_KINDS = [FactKind::Highlight];

    public function notes(string $source, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $product = $source === ChunkSource::Product->value;

        if (! $product && $source !== ChunkSource::Content->value) {
            return [];
        }

        // Labels in the store's language whoever started the build, so the text stays the same.
        $locale = app()->getLocale();
        app()->setLocale((string) config('app.locale'));

        try {
            return $this->lines($product, $ids);
        } finally {
            app()->setLocale($locale);
        }
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, list<string>>
     */
    private function lines(bool $product, array $ids): array
    {
        $facts = EnrichmentFact::query()->current()->where('status', FactStatus::Approved)
            ->whereIn($product ? 'product_id' : 'content_id', $ids)
            ->whereIn('kind', $product ? self::PRODUCT_KINDS : self::CONTENT_KINDS)
            ->with('vocabulary')->get();
        $notes = [];

        foreach ($facts->groupBy($product ? 'product_id' : 'content_id') as $id => $group) {
            $by = [];

            foreach ($group as $fact) {
                /** @var EnrichmentFact $fact */
                $value = trim($fact->valueLabel());

                if ($value === '' || ($fact->kind === FactKind::Tag && str_starts_with((string) $fact->key, 'ask_'))) {
                    continue;
                }

                $line = match ($fact->kind) {
                    FactKind::Type => 'Type: '.$value,
                    FactKind::Use => 'Use: '.$value,
                    FactKind::Tag => 'Tag: '.$value,
                    FactKind::Highlight => 'Highlight: '.$value,
                    default => trim($fact->keyLabel()).': '.$value,
                };
                $by[$fact->kind->value][$line] = true;
            }

            ksort($by);
            $lines = [];

            foreach ($by as $kind => $set) {
                $set = array_keys($set);
                sort($set);

                // Tags read better, and cost less, on one line.
                $lines = [...$lines, ...($kind === FactKind::Tag->value ? ['Tags: '.implode(', ', array_map(fn (string $l): string => substr($l, 5), $set))] : $set)];
            }

            if ($lines !== []) {
                $notes[(string) $id] = $lines;
            }
        }

        return $notes;
    }
}
