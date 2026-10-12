<?php

namespace App\Modules\Enrichment\Actions;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\AgentModel;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\SpendCapReached;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Models\EnrichmentSchemaProposal;
use App\Modules\Enrichment\Scanning\SchemaStats;
use App\Modules\Enrichment\Scanning\TextNormalizer;
use App\Modules\Enrichment\Scanning\Units;
use App\Modules\Enrichment\Vocabulary\VocabularyDefinition;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Models\Run;
use App\Modules\Tenancy\Models\Shop;

/**
 * The second step of a shop's own vocabulary: one model writes it from the counts (and from a
 * ready vocabulary when one fits), code checks that it holds together and measures each pattern
 * on the same sample, and a model of the other family reviews it against the counts. Code drops
 * what the review drops. Nothing is read with it until the operator approves it.
 */
final class ProposeSchema
{
    public const ACTION = 'enrichment.propose_schema';

    /** A ready vocabulary is a starting point when its types match at least this share of the titles. */
    public const TEMPLATE_FIT = 0.3;

    private const PROMPT_VERSION = 1;

    private const WRITER_OUTPUT_TOKENS = 12000;

    private const REVIEW_OUTPUT_TOKENS = 4000;

    /** The start of a description patterns are measured on, as code reads it. */
    private const HEAD = 600;

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
        private readonly ChatModel $models,
        private readonly SpendGuard $spend,
    ) {}

    public function handle(EnrichmentSchemaProposal $proposal): Run
    {
        return $this->runs->track(
            agent: DiscoverSchema::AGENT,
            action: self::ACTION,
            shopId: $proposal->shop_id,
            work: fn (RunContext $run) => $this->tenant->run($proposal->shop_id, fn () => $this->propose($run, $proposal)),
        );
    }

    private function propose(RunContext $run, EnrichmentSchemaProposal $proposal): void
    {
        $writer = AgentModel::from('enrichment.schema_writer_provider', 'enrichment.schema_writer_model');
        $reviewer = AgentModel::from('enrichment.schema_reviewer_provider', 'enrichment.schema_reviewer_model');

        // What one family writes, the other reviews.
        if ($writer->provider->family() === $reviewer->provider->family()) {
            $this->fail($run, $proposal, 'enrichment::runs.schema_same_family', ['same family']);

            return;
        }

        $stats = $proposal->stats;
        $template = $proposal->template === null ? null : ImportVocabulary::template($proposal->template);
        $texts = $this->texts((array) ($stats['sample'] ?? []));
        $input = [
            'store' => (string) Shop::query()->whereKey($proposal->shop_id)->value('name'),
            'stats' => array_diff_key($stats, ['sample' => true]),
            'template' => $template,
            'dimensions' => array_keys(Units::CANONICAL),
        ];

        try {
            $this->spend->assertCanSpend($this->estimate($input, 'writer', self::WRITER_OUTPUT_TOKENS) + $this->estimate($input, 'reviewer', self::REVIEW_OUTPUT_TOKENS));

            $written = $this->call($run, $writer, 'writer', 'schema_writer', $input, self::WRITER_OUTPUT_TOKENS);
            [$vocabulary, $problems] = $this->repair((array) ($written['vocabulary'] ?? []), $proposal);

            if ($vocabulary === null) {
                $this->fail($run, $proposal, 'enrichment::runs.schema_invalid', $problems);

                return;
            }

            $coverage = self::coverage($vocabulary, $texts);
            $review = $this->call($run, $reviewer, 'reviewer', 'schema_review', [
                'stats' => array_diff_key($stats, ['sample' => true, 'examples' => true]),
                'vocabulary' => array_intersect_key($vocabulary, array_flip(['product_types', 'attributes'])),
                'coverage' => $coverage,
            ], self::REVIEW_OUTPUT_TOKENS);
        } catch (SpendCapReached $e) {
            $this->fail($run, $proposal, 'assistant::runs.spend_cap', [$e->getMessage()]);

            return;
        } catch (ModelCallFailed $e) {
            $this->fail($run, $proposal, 'assistant::runs.model_failed', [$e->reason]);

            return;
        }

        [$vocabulary, $dropped] = self::drop($vocabulary, (array) ($review['drop'] ?? []));
        [$parsed, $left] = VocabularyDefinition::parse($vocabulary);

        if ($parsed === null) {
            $this->fail($run, $proposal, 'enrichment::runs.schema_invalid', [...$problems, ...$left]);

            return;
        }

        $proposal->update([
            'status' => EnrichmentSchemaProposal::PROPOSED,
            'proposed' => $vocabulary,
            'coverage' => self::coverage($vocabulary, $texts),
            'review' => [
                'verdict' => ($review['verdict'] ?? null) === 'good' ? 'good' : 'needs_work',
                'dropped' => $dropped,
                'missing' => array_slice(array_values(array_filter((array) ($review['missing'] ?? []), 'is_array')), 0, 15),
                'notes' => array_slice(array_map('strval', (array) ($review['notes'] ?? [])), 0, 10),
                'writer' => ['model' => $writer->model, 'sources' => (array) ($written['sources'] ?? []), 'notes' => array_slice(array_map('strval', (array) ($written['notes'] ?? [])), 0, 15)],
                'reviewer' => $reviewer->model,
            ],
            'problems' => $problems === [] ? null : $problems,
            'summary' => mb_substr(implode(' ', array_slice(array_map('strval', (array) ($written['notes'] ?? [])), 0, 3)), 0, 500),
        ]);

        $run->output(['proposal' => $proposal->id, 'types' => count($vocabulary['product_types']), 'attributes' => count($vocabulary['attributes'] ?? []), 'dropped' => count($dropped)])
            ->summary('enrichment::runs.schema_proposed', [
                'types' => (string) count($vocabulary['product_types']),
                'attributes' => (string) count($vocabulary['attributes'] ?? []),
                'dropped' => (string) count($dropped),
            ]);
    }

    /**
     * The writer's vocabulary, made to hold together: the fixed fields set by code, then every
     * product type and attribute that breaks the format left out, one by one.
     *
     * @return array{0: ?array<string, mixed>, 1: list<string>} the vocabulary (null when nothing holds) and what was left out
     */
    private function repair(array $vocabulary, EnrichmentSchemaProposal $proposal): array
    {
        $vocabulary['schema_version'] = VocabularyDefinition::SCHEMA_VERSION;
        $vocabulary['root_category_external_id'] = VocabularyDefinition::WHOLE_CATALOG;
        $vocabulary['key'] = preg_match('/^[a-z][a-z0-9_]{1,40}$/', (string) ($vocabulary['key'] ?? '')) === 1 ? $vocabulary['key'] : ($proposal->template ?? 'store');
        $vocabulary['product_types'] = array_values(array_filter((array) ($vocabulary['product_types'] ?? []), 'is_array'));
        $vocabulary['attributes'] = array_values(array_filter((array) ($vocabulary['attributes'] ?? []), 'is_array'));
        unset($vocabulary['uses'], $vocabulary['comparison'], $vocabulary['components'], $vocabulary['excluded_category_external_ids']);
        $problems = [];

        // Each entry tried alone with a known-good frame: what breaks it is left out, and said.
        $frame = fn (array $types, array $attributes): array => ['product_types' => $types, 'attributes' => $attributes, 'image' => []] + $vocabulary;
        foreach ($vocabulary['product_types'] as $i => $type) {
            [, $found] = VocabularyDefinition::parse($frame([$type], []));
            if ($found !== []) {
                $problems[] = 'product type '.($type['key'] ?? $i).': '.implode('; ', $found);
                unset($vocabulary['product_types'][$i]);
            }
        }
        $vocabulary['product_types'] = array_values($vocabulary['product_types']);
        if ($vocabulary['product_types'] === []) {
            return [null, [...$problems, 'no product type holds']];
        }
        foreach ($vocabulary['attributes'] as $i => $attribute) {
            [, $found] = VocabularyDefinition::parse($frame([$vocabulary['product_types'][0]], [$attribute]));
            if ($found !== []) {
                $problems[] = 'attribute '.($attribute['key'] ?? $i).': '.implode('; ', $found);
                unset($vocabulary['attributes'][$i]);
            }
        }
        $vocabulary['attributes'] = array_values($vocabulary['attributes']);

        // The picture's rules name only attributes that are left.
        $keys = [...array_column($vocabulary['attributes'], 'key'), VocabularyDefinition::PRODUCT_TYPE, 'default'];
        $image = (array) ($vocabulary['image'] ?? []);
        $image['never'] = array_values(array_intersect((array) ($image['never'] ?? []), $keys));
        $image['filter_confidence'] = array_intersect_key((array) ($image['filter_confidence'] ?? []), array_flip($keys));
        $vocabulary['image'] = $image;

        [$parsed, $left] = VocabularyDefinition::parse($vocabulary);

        return $parsed === null ? [null, [...$problems, ...$left]] : [$vocabulary, $problems];
    }

    /**
     * What each pattern list matches on the sample: product types on the title and the store's
     * type, attributes and their values on the title, type, tags and the start of the description.
     *
     * @param  list<array{head: string, full: string}>  $texts
     * @return array{product_types: array<string, float>, attributes: array<string, array{share: float, values?: array<string, float>}>}
     */
    public static function coverage(array $vocabulary, array $texts): array
    {
        $heads = array_column($texts, 'head');
        $fulls = array_column($texts, 'full');
        $out = ['product_types' => [], 'attributes' => []];

        foreach ((array) ($vocabulary['product_types'] ?? []) as $type) {
            $out['product_types'][(string) $type['key']] = SchemaStats::share($heads, (array) ($type['patterns'] ?? []));
        }
        foreach ((array) ($vocabulary['attributes'] ?? []) as $attribute) {
            $key = (string) $attribute['key'];
            $kind = $attribute['type'] ?? 'number';
            if ($kind === 'enum') {
                $values = [];
                foreach ((array) ($attribute['values'] ?? []) as $value) {
                    $values[(string) $value['key']] = SchemaStats::share($fulls, (array) ($value['patterns'] ?? []));
                }
                $all = array_merge(...array_map(fn (array $v): array => (array) ($v['patterns'] ?? []), (array) ($attribute['values'] ?? [])));
                $out['attributes'][$key] = ['share' => SchemaStats::share($fulls, $all), 'values' => $values];
            } elseif ($kind === 'boolean') {
                $out['attributes'][$key] = ['share' => SchemaStats::share($fulls, (array) ($attribute['patterns'] ?? []))];
            } else {
                $capture = $attribute['title_capture']['pattern'] ?? null;
                $out['attributes'][$key] = ['share' => is_string($capture) ? SchemaStats::share($fulls, [$capture]) : 0.0, 'read_by' => is_string($capture) ? 'code' : 'model'];
            }
        }

        return $out;
    }

    /**
     * The review's drops, applied by code: a product type, an attribute, or one value of a choice.
     *
     * @param  list<mixed>  $drops
     * @return array{0: array<string, mixed>, 1: list<array{kind: string, key: string, why: string}>}
     */
    public static function drop(array $vocabulary, array $drops): array
    {
        $done = [];
        foreach ($drops as $drop) {
            if (! is_array($drop) || ! is_string($drop['key'] ?? null)) {
                continue;
            }
            $key = $drop['key'];
            $why = mb_substr((string) ($drop['why'] ?? ''), 0, 200);
            $before = json_encode($vocabulary);

            if (($drop['kind'] ?? null) === 'product_type') {
                // The last type stays: a vocabulary needs one, and the operator sees the review.
                if (count($vocabulary['product_types']) > 1) {
                    $vocabulary['product_types'] = array_values(array_filter($vocabulary['product_types'], fn (array $t): bool => $t['key'] !== $key));
                }
            } elseif (($drop['kind'] ?? null) === 'value' && str_contains($key, '.')) {
                [$attribute, $value] = explode('.', $key, 2);
                foreach ($vocabulary['attributes'] as &$entry) {
                    if ($entry['key'] === $attribute && count((array) ($entry['values'] ?? [])) > 2) {
                        $entry['values'] = array_values(array_filter($entry['values'], fn (array $v): bool => $v['key'] !== $value));
                    }
                }
                unset($entry);
            } else {
                $vocabulary['attributes'] = array_values(array_filter($vocabulary['attributes'], fn (array $a): bool => $a['key'] !== $key));
            }

            if (json_encode($vocabulary) !== $before) {
                $done[] = ['kind' => (string) ($drop['kind'] ?? 'attribute'), 'key' => $key, 'why' => $why];
            }
        }

        $keys = [...array_column($vocabulary['attributes'], 'key'), VocabularyDefinition::PRODUCT_TYPE, 'default'];
        $vocabulary['image']['never'] = array_values(array_intersect((array) ($vocabulary['image']['never'] ?? []), $keys));
        $vocabulary['image']['filter_confidence'] = array_intersect_key((array) ($vocabulary['image']['filter_confidence'] ?? []), array_flip($keys));

        return [$vocabulary, $done];
    }

    /**
     * The sampled products' text as code reads it.
     *
     * @param  list<string>  $ids
     * @return list<array{head: string, full: string}>
     */
    private function texts(array $ids): array
    {
        return CatalogProduct::query()->whereIn('id', $ids)->orderBy('external_id')->get()->map(function (CatalogProduct $product): array {
            $head = TextNormalizer::clean((string) $product->title).' '.trim((string) ($product->payload['product_type'] ?? ''));
            $tags = implode(' ', array_map('strval', (array) ($product->payload['tags'] ?? [])));
            $description = mb_substr(TextNormalizer::clean(strip_tags($product->description())), 0, self::HEAD);

            return ['head' => $head, 'full' => $head.' '.$tags."\n".$description];
        })->all();
    }

    /** @return array<string, mixed> */
    private function call(RunContext $run, AgentModel $model, string $role, string $prompt, array $input, int $maxOutput): array
    {
        $reply = $this->models->json(
            $model->provider,
            $model->model,
            (string) file_get_contents(__DIR__.'/../Prompts/'.$prompt.'.v'.self::PROMPT_VERSION.'.md'),
            (string) json_encode($input, JSON_UNESCAPED_UNICODE),
            $maxOutput,
            'low',
        );
        $run->usage($model->provider->value, $model->model, $reply->inputTokens, $reply->outputTokens, 0, $reply->costUsd($this->price($role, 'input'), $this->price($role, 'output')));

        return $reply->data;
    }

    private function estimate(array $input, string $role, int $maxOutput): float
    {
        $tokens = (int) ceil(mb_strlen((string) json_encode($input, JSON_UNESCAPED_UNICODE)) / 3) + 2000;

        return ($tokens * $this->price($role, 'input') + $maxOutput * $this->price($role, 'output')) / 1_000_000;
    }

    private function price(string $role, string $side): float
    {
        return (float) Settings::get("enrichment.schema_{$role}_{$side}_usd_per_million");
    }

    /** @param  list<string>  $problems */
    private function fail(RunContext $run, EnrichmentSchemaProposal $proposal, string $key, array $problems): void
    {
        $proposal->update(['status' => EnrichmentSchemaProposal::FAILED, 'problems' => array_slice($problems, 0, 30)]);
        $run->output(['proposal' => $proposal->id, 'problems' => array_slice($problems, 0, 30)])->fail($key, [], mb_substr(implode('; ', $problems), 0, 2000));
    }
}
