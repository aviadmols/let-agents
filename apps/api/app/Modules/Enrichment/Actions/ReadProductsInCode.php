<?php

namespace App\Modules\Enrichment\Actions;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactOrigin;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentCodeReading;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Enrichment\Scanning\BrandResolver;
use App\Modules\Enrichment\Scanning\CodeReading;
use App\Modules\Enrichment\Scanning\NaturalColours;
use App\Modules\Enrichment\Scanning\PictureText;
use App\Modules\Enrichment\Scanning\ProductFamily;
use App\Modules\Enrichment\Scanning\TryOnEligibility;
use App\Modules\Enrichment\Support\VocabularyBranch;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Models\Run;
use Illuminate\Support\Facades\DB;

/**
 * The first pass over the whole catalog, in code only: no model, no cost, same result every
 * time. Every product gets a reading (see CodeReading), kept whole for the log. What code can
 * settle on its own becomes approved facts with origin "code": the brand, a type the category
 * stands for, sizes in the title. Model readings later answer only the rest.
 *
 * A product whose reading did not change is left alone, so running this again is cheap.
 */
final class ReadProductsInCode
{
    public const AGENT = 'enrichment.code_reader';

    public const ACTION = 'enrichment.read_in_code';

    private const SAMPLES = 20;

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
    ) {}

    public function handle(string $shopId): Run
    {
        return $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            work: fn (RunContext $run) => $this->tenant->run($shopId, fn () => $this->read($run, $shopId)),
        );
    }

    private function read(RunContext $run, string $shopId): void
    {
        $vocabularies = EnrichmentVocabulary::query()->where('active', true)->orderBy('key')->get();
        $candidates = [];

        foreach ($vocabularies as $vocabulary) {
            foreach (VocabularyBranch::products($vocabulary->definition())->pluck('id') as $productId) {
                $candidates[$productId][] = $vocabulary;
            }
        }

        // A product filed in two branches (a pine shelf under both wood and hardware) goes to the
        // vocabulary whose categories say what it is; when neither does, to the first by key.
        $branchOf = [];
        $overlaps = 0;

        foreach ($candidates as $productId => $options) {
            $branchOf[$productId] = $options[0];

            if (count($options) < 2) {
                continue;
            }

            $overlaps++;
            $overlapping = CatalogProduct::query()->whereKey($productId)->first();
            $categoryIds = $overlapping === null ? [] : ProductFamily::categoriesMostSpecificFirst($overlapping);

            foreach ($options as $option) {
                if ($option->definition()->typeForCategories($categoryIds) !== null) {
                    $branchOf[$productId] = $option;
                    break;
                }
            }
        }

        $brands = BrandResolver::forCatalog(CatalogProduct::query()->whereNull('removed_at')->lazyById(200));
        $autoApprove = Features::enabled('enrichment.auto_approve', $shopId);
        $readText = Features::enabled('enrichment.read_text_in_code', $shopId);
        // What a model saw in each picture, read last: only where the shop reads its text in code.
        $pictures = $readText ? PictureText::for() : [];
        $now = now();

        // The stones and stone colours already approved from a model's reading or a person: a stone's own
        // colour is implied from them too (NaturalColours), not only from what code reads here.
        $said = [];
        foreach (EnrichmentFact::query()->where('kind', FactKind::Choice)->whereIn('key', [NaturalColours::STONE, NaturalColours::COLOUR])
            ->where('status', FactStatus::Approved)->where('origin', '!=', FactOrigin::Code)->whereNotNull('product_id')->get(['product_id', 'key', 'value_text']) as $fact) {
            $said[$fact->product_id][$fact->key][] = (string) $fact->value_text;
        }

        $counts = ['products' => 0, 'changed' => 0, 'unchanged' => 0, 'facts' => 0, 'with_brand' => 0, 'types_from_category' => 0, 'title_specs' => 0, 'with_choices' => 0, 'tryon_yes' => 0, 'tryon_no' => 0, 'rentals' => 0, 'text_choices' => 0, 'text_specs' => 0, 'flags' => 0, 'implied_colours' => 0];
        $brandSources = [];
        $families = [];
        $samples = [];

        foreach (CatalogProduct::query()->whereNull('removed_at')->lazyById(200) as $product) {
            $counts['products']++;
            $vocabulary = $branchOf[$product->id] ?? null;
            $reading = CodeReading::read($product, $brands, $vocabulary?->definition(), $readText, isset($pictures[$product->id]) ? PictureText::text($pictures[$product->id]) : null);
            // A ruby is red: the colour its stones imply, where nothing says one of its own.
            $of = fn (string $key): array => [...(array) ($reading['text_choices'][$key]['values'] ?? []), ...(isset($reading['category_choices'][$key]) ? [(string) $reading['category_choices'][$key]] : []), ...($said[$product->id][$key] ?? [])];
            $implied = NaturalColours::implied($vocabulary?->definition(), $reading['type']['key'] ?? null, $of(NaturalColours::STONE), $of(NaturalColours::COLOUR));
            if ($implied !== []) {
                $reading['implied_choices'] = [NaturalColours::COLOUR => $implied];
                $counts['implied_colours']++;
            }
            $hash = hash('sha256', ($vocabulary?->id ?? '-').'|'.json_encode($reading, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            $counts['with_brand'] += isset($reading['brand']) ? 1 : 0;
            $counts['types_from_category'] += isset($reading['type']) ? 1 : 0;
            $counts['title_specs'] += count($reading['specs'] ?? []);
            $counts['with_choices'] += isset($reading['choices']) ? 1 : 0;
            $counts['rentals'] += isset($reading['rental']) ? 1 : 0;
            if (isset($reading['tryon'])) {
                $counts[$reading['tryon']['class'] === TryOnEligibility::NONE ? 'tryon_no' : 'tryon_yes']++;
            }
            $counts['text_choices'] += count($reading['text_choices'] ?? []);
            $counts['text_specs'] += count($reading['text_specs'] ?? []);
            $counts['flags'] += count($reading['flags'] ?? []);
            $source = $reading['brand']['source'] ?? 'none';
            $brandSources[$source] = ($brandSources[$source] ?? 0) + 1;

            if (isset($reading['family'])) {
                $families[$reading['family']['key']] = ($families[$reading['family']['key']] ?? 0) + 1;
            }

            $existing = EnrichmentCodeReading::query()->where('product_id', $product->id)->first();

            if ($existing !== null && $existing->reading_hash === $hash) {
                $counts['unchanged']++;

                continue;
            }

            $counts['changed']++;

            if (count($samples) < self::SAMPLES && (isset($reading['type']) || isset($reading['specs']) || ($source !== 'none' && $source !== 'store_field'))) {
                $samples[] = ['product' => $product->external_id, 'title' => mb_substr($product->title, 0, 80)] + array_intersect_key($reading, array_flip(['brand', 'type', 'specs', 'family']));
            }

            DB::transaction(function () use ($shopId, $product, $vocabulary, $reading, $hash, $autoApprove, $now, &$counts): void {
                EnrichmentCodeReading::query()->updateOrCreate(['product_id' => $product->id], [
                    'shop_id' => $shopId,
                    'vocabulary_id' => $vocabulary?->id,
                    'family_key' => $reading['family']['key'] ?? null,
                    'brand' => isset($reading['brand']) ? mb_substr($reading['brand']['brand'], 0, 120) : null,
                    'reading' => $reading,
                    'reading_hash' => $hash,
                    'read_at' => $now,
                ]);

                // Code's earlier facts about this product, unless a person decided on them.
                EnrichmentFact::query()
                    ->where('product_id', $product->id)
                    ->where('origin', FactOrigin::Code)
                    ->where('status', '!=', FactStatus::Superseded)
                    ->whereNull('decided_by')
                    ->update(['status' => FactStatus::Superseded, 'updated_at' => $now]);

                $status = $autoApprove ? FactStatus::Approved : FactStatus::NeedsPerson;
                // What the shop's team said of a detail stands: code does not write that detail again.
                $team = EnrichmentFact::query()->where('product_id', $product->id)->where('origin', FactOrigin::Person)
                    ->whereIn('status', [FactStatus::Approved, FactStatus::NotApplicable])->pluck('key')->all();
                $write = function (array $values) use ($shopId, $product, $hash, $status, $team, &$counts): void {
                    if (in_array($values['kind'], [FactKind::Choice, FactKind::Spec, FactKind::Flag], true) && in_array($values['key'], $team, true)) {
                        return;
                    }

                    EnrichmentFact::query()->create($values + [
                        'shop_id' => $shopId,
                        'product_id' => $product->id,
                        'origin' => FactOrigin::Code,
                        'status' => $status,
                        'input_hash' => $hash,
                        'model' => 'code',
                    ]);
                    $counts['facts']++;
                };

                if (isset($reading['brand'])) {
                    $write(['kind' => FactKind::Brand, 'key' => 'brand', 'value_text' => mb_substr($reading['brand']['brand'], 0, 255), 'quote' => $reading['brand']['quote'], 'status_reason' => 'code_'.$reading['brand']['source']]);
                }

                if (isset($reading['type'])) {
                    $source = $reading['type']['source'];
                    $write(['kind' => FactKind::Type, 'key' => 'type', 'value_text' => $reading['type']['key'], 'quote' => $product->title, 'vocabulary_id' => $vocabulary?->id, 'status_reason' => 'code_'.$source,
                        'confidence' => CodeReading::CONFIDENCE[$source] ?? null, 'evidence' => ['source' => $source]]);
                }

                if (isset($reading['tryon'])) {
                    $write(['kind' => FactKind::TryOn, 'key' => 'class', 'value_text' => $reading['tryon']['class'], 'quote' => $product->title, 'vocabulary_id' => $vocabulary?->id, 'status_reason' => 'code_'.$reading['tryon']['source'],
                        'confidence' => CodeReading::CONFIDENCE['category'], 'evidence' => ['source' => $reading['tryon']['source']]]);
                }

                foreach ((array) ($reading['category_choices'] ?? []) as $key => $value) {
                    $write(['kind' => FactKind::Choice, 'key' => $key, 'value_text' => $value, 'quote' => $product->title, 'vocabulary_id' => $vocabulary?->id, 'status_reason' => 'code_category']);
                }

                foreach ((array) ($reading['specs'] ?? []) as $key => $spec) {
                    $write(['kind' => FactKind::Spec, 'key' => $key, 'value_number' => $spec['value'], 'unit' => $spec['unit'], 'quote' => $spec['quote'], 'vocabulary_id' => $vocabulary?->id, 'status_reason' => 'code_title']);
                }

                // Read from the store's own words, each with how sure its source is and where it was read.
                foreach ((array) ($reading['text_choices'] ?? []) as $key => $choice) {
                    foreach ($choice['values'] as $value) {
                        $write(self::fromPicture($choice['source'], $key, $vocabulary, $status) + ['kind' => FactKind::Choice, 'key' => $key, 'value_text' => $value, 'quote' => mb_substr($choice['quote'], 0, 1000), 'vocabulary_id' => $vocabulary?->id, 'status_reason' => 'code_'.$choice['source'],
                            'confidence' => CodeReading::CONFIDENCE[$choice['source']], 'evidence' => ['source' => $choice['source'], 'ref' => mb_substr($choice['quote'], 0, 300)]]);
                    }
                }
                // Implied by what the product is made of (a ruby is red): code, never superseded by a model reading.
                foreach ((array) ($reading['implied_choices'] ?? []) as $key => $values) {
                    foreach ($values as $value) {
                        $write(['kind' => FactKind::Choice, 'key' => $key, 'value_text' => $value, 'quote' => $product->title, 'vocabulary_id' => $vocabulary?->id, 'status_reason' => 'code_implied', 'evidence' => ['source' => 'implied']]);
                    }
                }
                foreach ((array) ($reading['text_specs'] ?? []) as $key => $spec) {
                    $write(['kind' => FactKind::Spec, 'key' => $key, 'value_number' => $spec['value'], 'unit' => $spec['unit'], 'quote' => $spec['quote'], 'vocabulary_id' => $vocabulary?->id, 'status_reason' => 'code_'.$spec['source'],
                        'confidence' => CodeReading::CONFIDENCE[$spec['source']], 'evidence' => ['source' => $spec['source'], 'ref' => $spec['quote']]]);
                }
                foreach ((array) ($reading['flags'] ?? []) as $key => $flag) {
                    $write(self::fromPicture($flag['source'], $key, $vocabulary, $status) + ['kind' => FactKind::Flag, 'key' => $key, 'value_text' => 'true', 'quote' => mb_substr($flag['quote'], 0, 1000), 'vocabulary_id' => $vocabulary?->id, 'status_reason' => 'code_'.$flag['source'],
                        'confidence' => CodeReading::CONFIDENCE[$flag['source']], 'evidence' => ['source' => $flag['source'], 'ref' => mb_substr($flag['quote'], 0, 300)]]);
                }
            });
        }

        // Readings of products the store no longer publishes.
        EnrichmentCodeReading::query()->whereHas('product', fn ($q) => $q->whereNotNull('removed_at'))->delete();

        $familyCount = count(array_filter($families, fn (int $size): bool => $size >= 2));

        $run->output($counts + [
            'brand_sources' => $brandSources,
            'families' => $familyCount,
            'products_in_families' => array_sum(array_filter($families, fn (int $size): bool => $size >= 2)),
            'vocabularies' => $vocabularies->map(fn (EnrichmentVocabulary $v): string => $v->key.' v'.$v->version)->all(),
            'products_in_two_branches' => $overlaps,
            'samples' => $samples,
        ])->summary('enrichment::runs.read_in_code', [
            'products' => number_format($counts['products']),
            'changed' => number_format($counts['changed']),
            'brands' => number_format($counts['with_brand']),
            'families' => number_format($familyCount),
            'types' => number_format($counts['types_from_category']),
            'specs' => number_format($counts['title_specs']),
        ]);
    }

    /**
     * A fact read from what a model saw in the picture waits for a person, unless the vocabulary
     * lets a picture this sure decide it (a colour at 0.6); one from the store's words does not.
     *
     * @return array{status?: FactStatus}
     */
    private static function fromPicture(string $source, string $key, ?EnrichmentVocabulary $vocabulary, FactStatus $status): array
    {
        if ($source !== 'image' || $vocabulary === null || $status !== FactStatus::Approved) {
            return [];
        }

        return CodeReading::CONFIDENCE['image'] >= $vocabulary->definition()->imageFilterConfidence($key) ? [] : ['status' => FactStatus::AwaitingReview];
    }
}
