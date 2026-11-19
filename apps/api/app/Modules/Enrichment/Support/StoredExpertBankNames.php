<?php

namespace App\Modules\Enrichment\Support;

use App\Modules\Enrichment\Contracts\ExpertBankNames;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Enrichment\Scanning\PatternMatch;
use App\Modules\Enrichment\Scanning\TextNormalizer;

/** The category names the store's active expert-bank vocabulary already says (ExpertBankNames). */
final class StoredExpertBankNames implements ExpertBankNames
{
    public function saidByExpertBank(array $names): ?array
    {
        $labels = [];
        $patterns = [];
        $any = false;
        foreach (EnrichmentVocabulary::query()->where('active', true)->orderBy('key')->get() as $vocabulary) {
            $definition = $vocabulary->definition();
            if ($definition->expertBankId() === null) {
                continue;
            }
            $any = true;
            $items = $definition->productTypes();
            foreach ($definition->attributes() as $attribute) {
                if (($attribute['type'] ?? 'number') === 'enum') {
                    array_push($items, ...array_values((array) ($attribute['values'] ?? [])));
                }
            }
            foreach ($items as $item) {
                foreach ((array) ($item['label'] ?? []) as $label) {
                    $labels[TextNormalizer::forMatching((string) $label)] = true;
                }
                foreach ((array) ($item['patterns'] ?? []) as $pattern) {
                    $patterns[(string) $pattern] = true;
                }
            }
        }
        if (! $any) {
            return null;
        }

        $said = [];
        foreach ($names as $id => $name) {
            $name = TextNormalizer::forMatching((string) $name);
            if ($name === '') {
                continue;
            }
            if (isset($labels[$name]) || self::wholeName($name, array_keys($patterns))) {
                $said[] = (string) $id;
            }
        }

        return $said;
    }

    /**
     * Whether one of the patterns reads the whole name: nothing but a joined letter before it and an ending after it.
     * "Italy" and "לאיטליה" are the value Italy; "Italy & Greece" is a name of its own and stays.
     *
     * @param  list<string>  $patterns
     */
    private static function wholeName(string $name, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            foreach (PatternMatch::all($name, $pattern) as [$offset, $length]) {
                $before = (string) preg_replace('/\p{L}*\z/u', '', substr($name, 0, $offset));
                $after = (string) preg_replace('/^\p{L}*/u', '', substr($name, $offset + $length));
                if (preg_match('/[\p{L}\p{N}]/u', $before.$after) !== 1) {
                    return true;
                }
            }
        }

        return false;
    }
}
