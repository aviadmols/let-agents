<?php

namespace App\Modules\Enrichment\Support;

use App\Modules\Enrichment\Contracts\ProductTypes;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Models\EnrichmentTypeAudit;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Enrichment\Scanning\TypeDoubt;

/** The products' approved types and the types the store's names say, from the active vocabularies (ProductTypes). */
final class StoredProductTypes implements ProductTypes
{
    /** @var array<string, list<string>>|null every active vocabulary's type patterns, by type key, read once */
    private ?array $patterns = null;

    /** @var array<string, string|null> what each name asked so far says */
    private array $named = [];

    public function settled(): array
    {
        $out = [];
        // a product the audit found to be of no type of the bank: settled as none, unless a type was approved since
        EnrichmentTypeAudit::query()->where('status', EnrichmentTypeAudit::REMOVED)->orderBy('id')
            ->each(function (EnrichmentTypeAudit $audit) use (&$out): void {
                $out[(string) $audit->product_id] = null;
            });
        EnrichmentFact::query()->where('kind', FactKind::Type)->where('status', FactStatus::Approved)->whereNotNull('product_id')
            ->orderBy('id')->select(['product_id', 'value_text'])
            ->lazyById(2000)->each(function (EnrichmentFact $fact) use (&$out): void {
                $out[(string) $fact->product_id] = (string) $fact->value_text;
            });

        return $out;
    }

    public function namedBy(array $names): array
    {
        $patterns = $this->patterns ??= $this->readPatterns();
        $out = [];
        foreach ($names as $name) {
            $name = (string) $name;
            if (! array_key_exists($name, $this->named)) {
                $keys = $patterns === [] ? [] : TypeDoubt::typesNamed($name, $patterns);
                $this->named[$name] = count($keys) === 1 ? $keys[0] : null;
            }
            if ($this->named[$name] !== null) {
                $out[$name] = $this->named[$name];
            }
        }

        return $out;
    }

    /** @return array<string, list<string>> */
    private function readPatterns(): array
    {
        $out = [];
        foreach (EnrichmentVocabulary::query()->where('active', true)->orderBy('key')->get() as $vocabulary) {
            foreach (TypeDoubt::patterns($vocabulary->definition()) as $key => $patterns) {
                $out[$key] = [...($out[$key] ?? []), ...$patterns];
            }
        }

        return $out;
    }
}
