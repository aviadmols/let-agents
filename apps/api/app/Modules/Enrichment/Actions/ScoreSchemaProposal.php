<?php

namespace App\Modules\Enrichment\Actions;

use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Models\EnrichmentSchemaProposal;
use App\Modules\Enrichment\Scanning\BrandResolver;
use App\Modules\Enrichment\Scanning\CodeReading;
use App\Modules\Enrichment\Vocabulary\VocabularyDefinition;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Models\Run;

/**
 * A number for a proposed vocabulary, 0 to 100, from what code can measure and what the reviewing
 * model judged (docs/strict-scan.md). No model is called here.
 *
 *   types     20  how much of the branch the product types read, on the sample
 *   fill      40  a pilot: code reads the sample's products with the proposed vocabulary (nothing
 *                 saved), and counts how many of what a buyer must know it found. Required counts
 *                 in full, recommended by half. A vocabulary that names none is measured on all its
 *                 attributes, and says so. The kind of product (`read_by: model`) is left out: a model
 *                 reads it, not code.
 *   form      15  what code had to leave out to make the vocabulary hold together
 *   review    25  the verdict of the reviewer of the other family, less what it dropped and found missing
 *
 * Whatever the number, a vocabulary that reads no product type at all, or has no pilot to read,
 * cannot pass: those are the hard failures, which only a person overrides.
 */
final class ScoreSchemaProposal
{
    public const ACTION = 'enrichment.score_schema';

    /** The products the pilot reads: the first of the sample, in a fixed order. */
    public const PILOT = 40;

    public const WEIGHTS = ['types' => 20, 'fill' => 40, 'form' => 15, 'review' => 25];

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
    ) {}

    public function handle(EnrichmentSchemaProposal $proposal): Run
    {
        return $this->runs->track(
            agent: DiscoverSchema::AGENT,
            action: self::ACTION,
            shopId: $proposal->shop_id,
            work: fn (RunContext $run) => $this->tenant->run($proposal->shop_id, function () use ($run, $proposal): void {
                $scored = $this->compute($proposal);
                $proposal->update(['score' => $scored['score'], 'score_parts' => $scored['parts'], 'hard_failures' => $scored['hard'] === [] ? null : $scored['hard']]);
                $run->output(['proposal' => $proposal->id] + $scored)->summary('enrichment::runs.schema_scored', [
                    'branch' => (string) ($proposal->branch['name'] ?? '*'),
                    'score' => (string) $scored['score'],
                    'hard' => (string) count($scored['hard']),
                ]);
            }),
        );
    }

    /**
     * @return array{score: int, parts: array<string, mixed>, hard: list<string>}
     */
    public function measure(EnrichmentSchemaProposal $proposal): array
    {
        return $this->tenant->run($proposal->shop_id, fn (): array => $this->compute($proposal));
    }

    /** @return array{score: int, parts: array<string, mixed>, hard: list<string>} */
    private function compute(EnrichmentSchemaProposal $proposal): array
    {
        if ($proposal->proposed === null) {
            return ['score' => 0, 'parts' => [], 'hard' => ['no_vocabulary']];
        }

        $definition = VocabularyDefinition::fromTrusted($proposal->proposed);
        $coverage = (array) ($proposal->coverage ?? []);
        $hard = [];

        // Types: the shares of the sample each product type reads, together, at most all of it.
        $typeShare = min(1.0, array_sum(array_map('floatval', (array) ($coverage['product_types'] ?? []))));

        if ($typeShare === 0.0) {
            $hard[] = 'no_type_read';
        }

        $pilot = $this->pilot($definition, array_slice((array) ($proposal->stats['sample'] ?? []), 0, self::PILOT));

        if ($pilot['products'] === 0) {
            $hard[] = 'no_pilot';
        }

        // Form: each thing code had to leave out costs a little.
        $left = count((array) $proposal->problems);
        $form = max(0.0, 1.0 - 0.15 * $left);

        // Review: the verdict, less what the reviewer dropped and what it found missing.
        $review = (array) ($proposal->review ?? []);
        $dropped = count(array_filter((array) ($review['dropped'] ?? []), fn ($d): bool => ($d['by'] ?? null) === 'reviewer'));
        $missing = count((array) ($review['missing'] ?? []));
        $verdict = ($review['verdict'] ?? null) === 'good' ? 1.0 : 0.35;
        $judged = max(0.0, $verdict - min(0.4, 0.05 * $dropped) - min(0.2, 0.04 * $missing));

        $parts = [
            'types' => round(self::WEIGHTS['types'] * $typeShare, 1),
            'fill' => round(self::WEIGHTS['fill'] * $pilot['fill'], 1),
            'form' => round(self::WEIGHTS['form'] * $form, 1),
            'review' => round(self::WEIGHTS['review'] * $judged, 1),
        ];

        return [
            'score' => (int) round(array_sum($parts)),
            'parts' => $parts + ['pilot' => $pilot],
            'hard' => $hard,
        ];
    }

    /**
     * Reads the pilot's products in code with the proposed vocabulary and counts what it found of
     * what a buyer must know. Nothing is saved.
     *
     * @param  list<string>  $ids
     * @return array{products: int, fill: float, asked: list<string>, measured_on: string, found: array<string, int>}
     */
    private function pilot(VocabularyDefinition $definition, array $ids): array
    {
        $products = CatalogProduct::query()->whereIn('id', $ids)->orderBy('external_id')->get();
        // The kind of product is read by a model from the whole description, never by code: a pilot in code
        // would find it on no product and count that against a vocabulary that is right to have it.
        $inCode = fn (array $attributes): array => array_values(array_filter(array_column($attributes, 'key'), fn (string $key): bool => ! $definition->readByModel($key)));
        $required = $inCode($definition->attributesOf(VocabularyDefinition::REQUIRED));
        $recommended = $inCode($definition->attributesOf(VocabularyDefinition::RECOMMENDED));
        $weights = array_fill_keys($required, 1.0) + array_fill_keys($recommended, 0.5);
        $measuredOn = 'asked';

        // A vocabulary that asks for nothing is measured on everything it reads: weaker, and said.
        if ($weights === []) {
            $weights = array_fill_keys($inCode($definition->attributes()), 1.0);
            $measuredOn = 'all';
        }

        if ($products->isEmpty() || $weights === []) {
            return ['products' => $products->count(), 'fill' => 0.0, 'asked' => array_keys($weights), 'measured_on' => $measuredOn, 'found' => []];
        }

        $brands = BrandResolver::forCatalog($products);
        $found = array_fill_keys(array_keys($weights), 0);
        $possible = 0.0;
        $got = 0.0;

        foreach ($products as $product) {
            $reading = CodeReading::read($product, $brands, $definition, readText: true);
            $type = $reading['type']['key'] ?? null;

            foreach ($weights as $key => $weight) {
                if (! $definition->appliesTo((string) $key, $type)) {
                    continue;
                }

                $possible += $weight;

                if (isset($reading['category_choices'][$key]) || isset($reading['text_choices'][$key]) || isset($reading['specs'][$key]) || isset($reading['text_specs'][$key]) || isset($reading['flags'][$key])) {
                    $got += $weight;
                    $found[$key]++;
                }
            }
        }

        return ['products' => $products->count(), 'fill' => $possible > 0 ? round($got / $possible, 4) : 0.0, 'asked' => array_keys($weights), 'measured_on' => $measuredOn, 'found' => $found];
    }
}
