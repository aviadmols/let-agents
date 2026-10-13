<?php

namespace App\Modules\Enrichment\Tasks;

use App\Core\Facades\Settings;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactOrigin;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Enums\ItemStatus;
use App\Modules\Enrichment\Enums\TaskType;
use App\Modules\Enrichment\Models\EnrichmentBatch;
use App\Modules\Enrichment\Models\EnrichmentBatchItem;
use App\Modules\Enrichment\Models\EnrichmentCodeReading;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Scanning\Boilerplate;
use App\Modules\Enrichment\Scanning\ProductDigest;
use App\Modules\Enrichment\Scanning\TextNormalizer;
use App\Modules\Enrichment\Support\FactWriter;
use App\Modules\Enrichment\Support\VocabularyBranch;
use App\Modules\Enrichment\Vocabulary\VocabularyDefinition;
use Illuminate\Support\Collection;

/**
 * Reads products in one vocabulary's branch of the catalog.
 *
 * Code reads first (ReadProductsInCode): brand, a type the category stands for, sizes in the
 * title. The request carries those as `known`, and the model answers only the rest.
 *
 * What gets approved without review: a claim code and the model agree on, and nothing else
 * could have meant. A single product-type hint the model chose; a candidate phrase the model
 * accepted; a measurement whose unit can only mean one spec for this type and that appears with
 * one value. Everything else waits for the reviewer, and "good for" jobs always do.
 */
final class ProductExtractionTask implements AgentTask
{
    public const SUBJECT = 'product';

    private const MAX_QUOTE_CHARS = 200;

    private const MAX_USES = 6;

    /** Wording that says a number belongs to something optional or other. Such specs are always checked. */
    private const OPTIONAL_MENTION = '~בנפרד|לרכוש|לרכישה|אופציונ|להשכרה|תואם ל|מתאים ל|separately|optional|compatible~iu';

    /** @var Collection<string, array<string, mixed>>|null code readings by product ID, per batch */
    private ?Collection $readings = null;

    public function __construct(private readonly FactWriter $facts) {}

    public function type(): TaskType
    {
        return TaskType::ProductExtraction;
    }

    public function requests(EnrichmentBatch $batch, int $limit): iterable
    {
        $vocabulary = $batch->vocabulary?->definition();

        if ($vocabulary === null) {
            return;
        }

        $maxChars = (int) Settings::get('enrichment.max_agent_text_chars', $batch->shop_id);
        $includeDone = (bool) ($batch->scope['include_done'] ?? false);
        $count = 0;
        $this->readings = null;

        // Text pasted into many products of this branch is found first and kept with the batch,
        // so checking an answer later removes exactly the same lines.
        $boilerplate = Boilerplate::find((function () use ($vocabulary) {
            foreach (VocabularyBranch::products($vocabulary)->lazyById(200) as $product) {
                yield ProductDigest::sections($product);
            }
        })());

        $batch->forceFill(['scope' => ($batch->scope ?? []) + ['boilerplate' => $boilerplate]])->save();

        // Some products only, read again whatever was read before: what the gaps screen sends.
        $only = array_values(array_map('strval', (array) ($batch->scope['products'] ?? [])));
        $products = VocabularyBranch::products($vocabulary)->when($only !== [], fn ($q) => $q->whereIn('id', $only));

        foreach ($products->lazyById(200) as $product) {
            $digest = ProductDigest::build($product, $vocabulary, $maxChars, $batch->prompt_hash, $boilerplate, $this->known($product->id, $vocabulary));

            if (! $includeDone && $this->alreadyRead($product->id, $digest->inputHash)) {
                continue;
            }

            yield new TaskRequest(
                customId: 'px-'.$product->external_id.'-'.substr($digest->inputHash, 0, 10),
                subjectType: self::SUBJECT,
                subjectId: $product->id,
                inputHash: $digest->inputHash,
                request: $digest->request,
                context: $digest->context,
            );

            if (++$count >= $limit) {
                return;
            }
        }
    }

    public function isStale(EnrichmentBatch $batch, EnrichmentBatchItem $item): bool
    {
        $product = CatalogProduct::query()->find($item->subject_id);
        $vocabulary = $batch->vocabulary?->definition();

        if ($product === null || $product->removed_at !== null || $vocabulary === null) {
            return true;
        }

        $maxChars = (int) Settings::get('enrichment.max_agent_text_chars', $batch->shop_id);
        $digest = ProductDigest::build($product, $vocabulary, $maxChars, $batch->prompt_hash, (array) ($batch->scope['boilerplate'] ?? []), $this->known($product->id, $vocabulary));

        return $digest->inputHash !== $item->input_hash;
    }

    public function apply(EnrichmentBatch $batch, EnrichmentBatchItem $item, array $output, string $model): ItemOutcome
    {
        $vocabulary = $batch->vocabulary?->definition();

        if ($vocabulary === null) {
            return ItemOutcome::rejected(['vocabulary_missing']);
        }

        if ((string) ($output['id'] ?? '') !== (string) $item->request['id']) {
            return ItemOutcome::rejected(['wrong_id']);
        }

        $problems = [];
        $saved = [];
        $context = $item->context;
        $known = (array) ($item->request['known'] ?? []);

        // Code's own facts are not replaced by a model reading; see ReadProductsInCode.
        $this->facts->supersedePrevious('product_id', $item->subject_id, [FactKind::Type, FactKind::Spec, FactKind::Choice, FactKind::Flag, FactKind::Tag, FactKind::Use]);

        // Type. When the category already says what the product is, code's type stands.
        $type = $output['type'] ?? null;
        $typeKey = isset($known['type']) ? (string) $known['type'] : null;

        if ($typeKey !== null) {
            if (is_string($type) && $type !== '' && $type !== $typeKey) {
                $problems[] = "type_differs_from_code:{$type}";
            }
        } elseif (is_string($type) && $type !== '') {
            if (! $vocabulary->hasProductType($type)) {
                $problems[] = "unknown_type:{$type}";
            } else {
                $typeKey = $type;
                $hints = (array) ($context['type_hints'] ?? []);
                $agreed = $hints === [$type];

                $saved[] = $this->facts->write($batch, $item, 'product_id', $model, [
                    'kind' => FactKind::Type,
                    'key' => 'type',
                    'value_text' => $type,
                    'quote' => $item->request['title'] ?? null,
                    'origin' => in_array($type, $hints, true) ? FactOrigin::CodeAndModel : FactOrigin::Model,
                    'status' => $agreed ? FactStatus::Approved : FactStatus::AwaitingReview,
                    'status_reason' => $agreed ? null : ($hints === [] ? 'no_hint' : (in_array($type, $hints, true) ? 'several_hints' : 'differs_from_hint')),
                ]);
            }
        }

        // Specs: a spec key mapped to a measurement code found. Specs code read from the title stand.
        $measurements = collect((array) ($context['measurements'] ?? []))->keyBy('id');
        $specs = is_array($output['specs'] ?? null) ? $output['specs'] : [];

        foreach ($specs as $key => $measurementId) {
            $key = (string) $key;

            if (isset($known['specs'][$key])) {
                continue;
            }

            $attribute = $vocabulary->attribute($key);
            $measurement = is_string($measurementId) ? $measurements->get($measurementId) : null;

            $problem = match (true) {
                $attribute === null || $attribute['type'] !== 'number' => "unknown_spec:{$key}",
                $measurement === null => "unknown_measurement:{$key}",
                $measurement['dimension'] !== $attribute['dimension'] => "wrong_unit:{$key}",
                (float) $measurement['value'] < (float) $attribute['min'] || (float) $measurement['value'] > (float) $attribute['max'] => "out_of_range:{$key}",
                ! $vocabulary->appliesTo($key, $typeKey) => "not_applicable:{$key}",
                in_array(TextNormalizer::forMatching($vocabulary->label('attribute', $key, 'he')), (array) ($context['choice_names'] ?? []), true) => "chosen_by_shopper:{$key}",
                default => null,
            };

            if ($problem !== null) {
                $problems[] = $problem;

                continue;
            }

            $unambiguous = $this->onlySpecFor($vocabulary, $measurement['dimension'], $typeKey) === $key
                && $this->distinctValues($measurement['dimension'] === '' ? [] : $measurements->where('dimension', $measurement['dimension'])->pluck('value')->all()) === 1;
            // "A 4.0Ah battery can be bought separately": the number is real but not this product's.
            $optional = (bool) preg_match(self::OPTIONAL_MENTION, (string) $measurement['quote']);

            $saved[] = $this->facts->write($batch, $item, 'product_id', $model, [
                'kind' => FactKind::Spec,
                'key' => $key,
                'value_number' => round((float) $measurement['value'], 6),
                'unit' => $measurement['unit'],
                'quote' => $measurement['quote'],
                'origin' => FactOrigin::CodeAndModel,
                'status' => $unambiguous && ! $optional ? FactStatus::Approved : FactStatus::AwaitingReview,
                'status_reason' => $optional ? 'optional_mention' : ($unambiguous ? null : 'ambiguous_measurement'),
            ]);
        }

        // Candidates the model accepted: choices, yes/no specs, tags. Job candidates join the uses below.
        $candidates = collect((array) ($context['candidates'] ?? []))->keyBy('id');
        $accepted = collect(is_array($output['yes'] ?? null) ? $output['yes'] : [])
            ->filter(fn ($id): bool => is_string($id))
            ->unique()
            ->map(function (string $id) use ($candidates, &$problems): ?array {
                $candidate = $candidates->get($id);
                if ($candidate === null) {
                    $problems[] = "unknown_candidate:{$id}";
                }

                return $candidate;
            })
            ->filter()
            ->values();

        $choiceCounts = $accepted->where('kind', 'enum')->countBy('key');
        $uses = $accepted->where('kind', 'use')->pluck('value')->all();

        // Choices the category already settled stand as code wrote them.
        foreach ($accepted->where('kind', '!=', 'use')->reject(fn (array $c): bool => $c['kind'] === 'enum' && isset($known['choices'][$c['key']])) as $candidate) {
            $conflict = $candidate['kind'] === 'enum' && $choiceCounts->get($candidate['key'], 0) > 1;
            // Tag wording is loose ("professional" also describes a result or a brand), and a tag
            // is something a shopper reads. Tags are always checked by a second model.
            $isTag = $candidate['kind'] === 'tag';

            $saved[] = $this->facts->write($batch, $item, 'product_id', $model, $this->candidateFact($candidate) + [
                'origin' => FactOrigin::CodeAndModel,
                'status' => $conflict || $isTag ? FactStatus::AwaitingReview : FactStatus::Approved,
                'status_reason' => $conflict ? 'conflicting_choices' : ($isTag ? 'tag' : null),
            ]);
        }

        // Jobs the product is good for, from the vocabulary's list. Often not written in the text
        // ("pressure-treated pine" says nothing about pergolas), so every one goes to the reviewer.
        // Models sometimes answer a job with its candidate id ("c3"): read it as the job it names.
        $answeredUses = array_map(
            fn (string $use): string => ($c = $candidates->get($use)) !== null && $c['kind'] === 'use' ? (string) $c['value'] : $use,
            array_filter(is_array($output['uses'] ?? null) ? $output['uses'] : [], 'is_string'),
        );

        foreach (array_slice(array_values(array_unique([...$uses, ...$answeredUses])), 0, self::MAX_USES) as $use) {
            $definition = collect($vocabulary->uses())->firstWhere('key', $use);

            if ($definition === null) {
                $problems[] = "unknown_use:{$use}";

                continue;
            }

            if (($definition['types'] ?? []) !== [] && $typeKey !== null && ! in_array($typeKey, (array) $definition['types'], true)) {
                $problems[] = "use_not_for_type:{$use}";

                continue;
            }

            $evidence = $candidates->first(fn (array $c): bool => $c['kind'] === 'use' && $c['value'] === $use && $c['quote'] !== '');

            $saved[] = $this->facts->write($batch, $item, 'product_id', $model, [
                'kind' => FactKind::Use,
                'key' => 'use',
                'value_text' => $use,
                'quote' => $evidence['quote'] ?? null,
                'origin' => $evidence === null ? FactOrigin::Model : FactOrigin::CodeAndModel,
                'status' => FactStatus::AwaitingReview,
                'status_reason' => 'use',
            ]);
        }

        // Additions: the model alone, with a quote code can find.
        $existing = collect($saved)->map(fn (EnrichmentFact $f): string => $f->kind->value.'|'.$f->key.'|'.$f->value_text)->all();

        foreach (is_array($output['add'] ?? null) ? $output['add'] : [] as $index => $addition) {
            [$fact, $problem] = $this->addition($vocabulary, $addition, (string) ($context['text'] ?? ''), $index);

            if ($problem !== null) {
                $problems[] = $problem;

                continue;
            }

            if (in_array($fact['kind']->value.'|'.$fact['key'].'|'.$fact['value_text'], $existing, true)) {
                continue;
            }

            $saved[] = $this->facts->write($batch, $item, 'product_id', $model, $fact + [
                'origin' => FactOrigin::Model,
                'status' => FactStatus::AwaitingReview,
                'status_reason' => 'model_only',
            ]);
        }

        return new ItemOutcome(ItemStatus::Applied, count($saved), count($problems), $problems);
    }

    /**
     * What code already knows about a product, sent with the request so the model does not
     * answer it again: brand, a type the category stands for, sizes read from the title.
     *
     * @return array<string, mixed>
     */
    private function known(string $productId, VocabularyDefinition $vocabulary): array
    {
        $this->readings ??= EnrichmentCodeReading::query()->get(['product_id', 'reading'])->pluck('reading', 'product_id');
        $reading = (array) ($this->readings->get($productId) ?? []);

        $known = ['brand' => $reading['brand']['brand'] ?? null];

        if (($reading['vocabulary'] ?? null) === $vocabulary->key()) {
            $known['type'] = $reading['type']['key'] ?? null;
            $known['choices'] = (array) ($reading['category_choices'] ?? []);
            $known['specs'] = array_map(fn (array $spec): array => [$spec['value'], $spec['unit']], (array) ($reading['specs'] ?? []));
        }

        return array_filter($known, fn ($value): bool => $value !== null && $value !== []);
    }

    private function alreadyRead(string $productId, string $inputHash): bool
    {
        return EnrichmentFact::query()
            ->where('product_id', $productId)
            ->where('input_hash', $inputHash)
            ->where('status', '!=', FactStatus::Superseded)
            ->exists()
            || EnrichmentBatchItem::query()
                ->where('subject_id', $productId)
                ->where('input_hash', $inputHash)
                ->whereIn('status', [ItemStatus::Pending, ItemStatus::Applied])
                ->exists();
    }

    /**
     * How many different values there are, counting values within 2% as one: '7½"' (190.5 mm)
     * and "190 mm" in the same text are one size written twice.
     *
     * @param  list<float|int|string>  $values
     */
    private function distinctValues(array $values): int
    {
        $values = array_map('floatval', $values);
        sort($values);
        $distinct = 0;
        $last = null;

        foreach ($values as $value) {
            if ($last === null || abs($value - $last) > 0.02 * max(abs($value), abs($last))) {
                $distinct++;
                $last = $value;
            }
        }

        return $distinct;
    }

    /** The one number spec that a unit can mean for this product type, or null when several can. */
    private function onlySpecFor(VocabularyDefinition $vocabulary, string $dimension, ?string $type): ?string
    {
        $keys = [];
        foreach ($vocabulary->attributes() as $attribute) {
            if (($attribute['type'] ?? 'number') === 'number' && ($attribute['dimension'] ?? null) === $dimension && $vocabulary->appliesTo($attribute['key'], $type)) {
                $keys[] = $attribute['key'];
            }
        }

        return count($keys) === 1 ? $keys[0] : null;
    }

    /**
     * @param  array{kind: string, key: string, value: string|bool, quote: string}  $candidate
     * @return array<string, mixed>
     */
    private function candidateFact(array $candidate): array
    {
        return match ($candidate['kind']) {
            'enum' => ['kind' => FactKind::Choice, 'key' => $candidate['key'], 'value_text' => (string) $candidate['value'], 'quote' => $candidate['quote']],
            'boolean' => ['kind' => FactKind::Flag, 'key' => $candidate['key'], 'value_text' => 'true', 'quote' => $candidate['quote']],
            default => ['kind' => FactKind::Tag, 'key' => 'tag', 'value_text' => (string) $candidate['value'], 'quote' => $candidate['quote']],
        };
    }

    /**
     * @return array{0: array<string, mixed>|null, 1: string|null}
     */
    private function addition(VocabularyDefinition $vocabulary, mixed $addition, string $text, int|string $index): array
    {
        if (! is_array($addition) || ! is_string($addition['key'] ?? null)) {
            return [null, "invalid_addition:{$index}"];
        }

        $key = $addition['key'];
        $value = $addition['value'] ?? null;
        $quote = trim((string) ($addition['quote'] ?? ''));

        if ($quote === '' || mb_strlen($quote) > self::MAX_QUOTE_CHARS || ! TextNormalizer::contains($text, $quote)) {
            return [null, "quote_not_found:{$key}"];
        }

        if ($key === 'tag') {
            return is_string($value) && $vocabulary->hasTag($value)
                ? [['kind' => FactKind::Tag, 'key' => 'tag', 'value_text' => $value, 'quote' => $quote], null]
                : [null, 'unknown_tag:'.(is_scalar($value) ? $value : '?')];
        }

        if ($vocabulary->hasTag($key) && ($value === true || $value === $key || $value === 'true')) {
            return [['kind' => FactKind::Tag, 'key' => 'tag', 'value_text' => $key, 'quote' => $quote], null];
        }

        $attribute = $vocabulary->attribute($key);

        if ($attribute === null) {
            return [null, "unknown_key:{$key}"];
        }

        if ($attribute['type'] === 'boolean') {
            return $value === true || $value === 'true'
                ? [['kind' => FactKind::Flag, 'key' => $key, 'value_text' => 'true', 'quote' => $quote], null]
                : [null, "invalid_value:{$key}"];
        }

        if ($attribute['type'] === 'enum') {
            $allowed = array_column((array) $attribute['values'], 'key');

            return is_string($value) && in_array($value, $allowed, true)
                ? [['kind' => FactKind::Choice, 'key' => $key, 'value_text' => $value, 'quote' => $quote], null]
                : [null, "invalid_value:{$key}"];
        }

        // Numbers only ever come from measurements code found.
        return [null, "number_in_addition:{$key}"];
    }
}
