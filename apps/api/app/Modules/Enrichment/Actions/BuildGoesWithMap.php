<?php

namespace App\Modules\Enrichment\Actions;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\AgentModel;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\SpendCapReached;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Ai\Contracts\StructuredChatModel;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Models\EnrichmentGoesWithMap;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Enrichment\Support\ReplySchema;
use App\Modules\Enrichment\Support\ReplySchemaBroken;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Models\Run;
use App\Modules\Tenancy\Models\Shop;

/**
 * A store's goes-with map: which of its own kinds of products a shopper typically needs or uses with which (a drill: its
 * bits and screws; a deck board: its screws and stain; a dress: a belt and a bag; a bracelet: a ring of the set). Written
 * once per store, kept, and read by code at answer time (GoesWithPartners); the live answer never asks a model for it.
 *
 * 1. The types, in code: the store's product types from its active vocabularies, each with how many active products the
 *    approved facts file under it and a few titles. Fewer than two kinds with products: nothing to pair.
 * 2. One family writes the pairs {type, partner_type, why}, among those kinds only.
 * 3. Code keeps the shape: both keys of the list, no kind with itself, no pair twice, at most the cap per kind.
 * 4. The other family (checked in code before any call) scores every pair; it can take out and nothing else: a pair it
 *    adds or renames is ignored, one it leaves unscored is dropped, a score under the threshold drops it.
 * 5. Stored with every removal and every call's cost; one Hebrew line a step on the run. The same kinds, prompts, models
 *    and threshold are never asked twice (fingerprint), so a nightly run costs nothing until the kinds change.
 *
 * Off by default (enrichment.goes_with_map): a build costs a model call per store.
 */
final class BuildGoesWithMap
{
    public const AGENT = 'enrichment.goes_with_map';

    public const ACTION = 'enrichment.build_goes_with_map';

    public const FEATURE = 'enrichment.goes_with_map';

    /** The code's own version, part of the fingerprint: a change in what the writer is given or how pairs are kept asks again. */
    public const VERSION = 1;

    /** The released version each prompt is sent at; a released one is never edited. */
    public const PROMPTS = ['goes_with_map' => 1, 'goes_with_map_check' => 1];

    private const WRITER_TOKENS = 6000;

    private const CHECKER_TOKENS = 6000;

    /** Pairs one checker call scores. */
    public const CHECK_BATCH = 80;

    /** Titles of each kind the writer is shown, to know what the kind really is in this store. */
    private const EXAMPLES = 3;

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
        private readonly ChatModel $models,
        private readonly SpendGuard $spend,
    ) {}

    public function handle(string $shopId, bool $force = false): Run
    {
        return $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            input: ['force' => $force],
            work: function (RunContext $run) use ($shopId, $force): void {
                $this->tenant->run($shopId, fn (): ?EnrichmentGoesWithMap => $this->build($run, $shopId, $force));
            },
        );
    }

    private function build(RunContext $run, string $shopId, bool $force): ?EnrichmentGoesWithMap
    {
        if (! Features::enabled(self::FEATURE, $shopId)) {
            $run->fail('enrichment::runs.goes_with_off');

            return null;
        }

        $writer = AgentModel::from('enrichment.goes_with_writer_provider', 'enrichment.goes_with_writer_model');
        $checker = AgentModel::from('enrichment.goes_with_checker_provider', 'enrichment.goes_with_checker_model');
        // What one family writes, the other checks. Decided here, before a model is asked.
        if ($writer->provider->family() === $checker->provider->family()) {
            $run->fail('enrichment::runs.goes_with_same_family', [], 'same family: '.$writer->provider->value.' / '.$checker->provider->value);

            return null;
        }

        $limits = [
            'min_score' => (int) Settings::get('enrichment.goes_with_min_score', $shopId),
            'per_type' => (int) Settings::get('enrichment.goes_with_partners_per_type', $shopId),
            'max_types' => (int) Settings::get('enrichment.goes_with_max_types', $shopId),
        ];
        $shop = Shop::query()->whereKey($shopId)->first(['name', 'content_locale']);
        $locale = (string) ($shop?->content_locale ?: 'he');

        // 1. The kinds, in code.
        $types = $this->types($locale, $limits['max_types']);
        if (count($types) < 2) {
            $run->fail('enrichment::runs.goes_with_nothing', ['types' => count($types)]);

            return null;
        }

        $steps = [];
        $say = function (string $key, array $params = []) use (&$steps, $run): void {
            $steps[] = (string) trans('enrichment::runs.'.$key, $params, 'he');
            $run->output(['steps' => $steps]);
        };
        $say('goes_with_step_types', ['types' => count($types), 'products' => array_sum(array_column($types, 'products'))]);

        // the kinds themselves, never their counts or titles: a product added changes no pair
        $keys = array_map(fn (array $t): array => ['key' => $t['key'], 'label' => $t['label']], $types);
        $fingerprint = hash('sha256', (string) json_encode([
            self::VERSION, self::PROMPTS, $writer->provider->value, $writer->model, $checker->provider->value, $checker->model, $limits['min_score'], $limits['per_type'], $keys,
        ], JSON_UNESCAPED_UNICODE));
        $last = EnrichmentGoesWithMap::query()->where('fingerprint', $fingerprint)->where('status', '!=', EnrichmentGoesWithMap::FAILED)->latest()->first();
        if ($last !== null && ! $force) {
            $run->output(['goes_with_map' => $last->id, 'unchanged' => true, 'pairs' => count((array) $last->pairs)])->summary('enrichment::runs.goes_with_unchanged', ['id' => $last->id, 'pairs' => count((array) $last->pairs)]);

            return $last;
        }

        $store = ['name' => (string) ($shop?->name ?? ''), 'language' => $locale];
        $record = [
            'shop_id' => $shopId,
            'fingerprint' => $fingerprint,
            'writer' => $writer->provider->value.' / '.$writer->model,
            'checker' => $checker->provider->value.' / '.$checker->model,
            'types' => $types,
            'run_id' => $run->runId,
        ];
        $calls = [];
        $removed = [];
        $proposed = [];

        try {
            // 2 and 3. The writer pairs the kinds; code keeps the shape.
            $reply = $this->ask($run, $writer, 'writer', 'goes_with_map', 'write', ['store' => $store, 'types' => $types, 'partners_per_type' => $limits['per_type']], self::WRITER_TOKENS, 'medium', $calls);
            $proposed = array_values(array_map(fn (array $p): array => ['type' => (string) $p['type'], 'partner_type' => (string) $p['partner_type'], 'why' => trim((string) $p['why'])], (array) $reply['pairs']));
            [$pairs, $unformed] = self::shaped($proposed, array_column($types, 'key'), $limits['per_type']);
            $removed = [...$removed, ...$unformed];
            $say('goes_with_step_written', ['model' => $writer->model, 'pairs' => count($proposed), 'removed' => count($unformed), 'usd' => self::usd($calls)]);

            // 4. The other family scores every pair; it can take out and nothing else.
            $labels = array_column($types, 'label', 'key');
            $forCheck = array_map(fn (array $p): array => ['id' => self::id($p), 'type' => $p['type'], 'type_label' => $labels[$p['type']] ?? $p['type'],
                'partner_type' => $p['partner_type'], 'partner_label' => $labels[$p['partner_type']] ?? $p['partner_type'], 'why' => $p['why']], $pairs);
            $ids = array_column($forCheck, 'id');
            $items = [];
            $before = count($calls);
            foreach (array_chunk($ids, self::CHECK_BATCH) as $part => $chunk) {
                $reply = $this->ask($run, $checker, 'checker', 'goes_with_map_check', 'check_'.($part + 1), ['store' => $store, 'types' => $types, 'pairs' => $forCheck, 'score' => $chunk], self::CHECKER_TOKENS, 'medium', $calls);
                $items = [...$items, ...array_values((array) ($reply['items'] ?? []))];
            }
            [$verdicts, $ignored] = self::verdicts($ids, $items);
            $kept = [];
            foreach ($pairs as $pair) {
                $verdict = $verdicts[self::id($pair)] ?? null;
                if ($verdict === null) {
                    $removed[] = $pair + ['by' => 'checker', 'why_removed' => 'unscored'];
                } elseif ($verdict['verdict'] !== 'keep' || $verdict['score'] < $limits['min_score']) {
                    $removed[] = $pair + ['by' => 'checker', 'why_removed' => $verdict['verdict'] !== 'keep' ? 'dropped' : 'under_threshold', 'score' => $verdict['score'], 'reason' => $verdict['reason']];
                } else {
                    $kept[] = $pair + ['score' => $verdict['score']];
                }
            }
            $say('goes_with_step_check', ['model' => $checker->model, 'scored' => count($ids), 'dropped' => count($pairs) - count($kept), 'ignored' => count($ignored), 'usd' => self::usd(array_slice($calls, $before))]);
        } catch (ReplySchemaBroken $e) {
            return $this->failed($run, $record, $calls, $steps, 'enrichment::runs.goes_with_schema', ['step' => $e->step], $e->problems, $removed, $proposed);
        } catch (SpendCapReached $e) {
            return $this->failed($run, $record, $calls, $steps, 'enrichment::runs.goes_with_spend_cap', [], [$e->getMessage()], $removed, $proposed);
        } catch (ModelCallFailed $e) {
            return $this->failed($run, $record, $calls, $steps, 'enrichment::runs.goes_with_model_failed', ['reason' => $e->reason], [$e->reason], $removed, $proposed);
        }

        $params = ['pairs' => count($kept), 'types' => count(array_unique(array_column($kept, 'type'))), 'removed' => count($removed), 'usd' => self::usd($calls)];
        $summary = $kept !== [] ? 'enrichment::runs.goes_with_passed' : 'enrichment::runs.goes_with_held';
        $steps[] = (string) trans($summary, $params, 'he');

        $stored = EnrichmentGoesWithMap::query()->create($record + [
            'status' => $kept !== [] ? EnrichmentGoesWithMap::PASSED : EnrichmentGoesWithMap::HELD,
            'proposed' => $proposed,
            'pairs' => $kept,
            'review' => ['threshold' => $limits['min_score'], 'items' => $verdicts, 'ignored' => $ignored],
            'removed' => $removed,
            'calls' => $calls,
            'steps' => $steps,
        ]);
        $run->output(['goes_with_map' => $stored->id, 'status' => $stored->status, 'pairs' => $kept, 'steps' => $steps, 'calls' => $calls])->summary($summary, $params);

        return $stored;
    }

    /**
     * The store's product types with products, as the active vocabularies name them in the store's language: key, label,
     * how many active products the approved facts file under each, and a few of their titles. The most products first.
     *
     * @return list<array{key: string, label: string, products: int, examples: list<string>}>
     */
    private function types(string $locale, int $cap): array
    {
        $vocabularies = EnrichmentVocabulary::query()->where('active', true)->orderBy('key')->get();
        if ($vocabularies->isEmpty()) {
            return [];
        }
        $labels = [];
        foreach ($vocabularies as $vocabulary) {
            $definition = $vocabulary->definition();
            foreach ($definition->productTypes() as $type) {
                $labels[(string) $type['key']] ??= (string) ($definition->label('type', (string) $type['key'], $locale) ?: $type['key']);
            }
        }
        $active = CatalogProduct::query()->active()->select('id');
        $counts = EnrichmentFact::query()->where('kind', FactKind::Type)->where('status', FactStatus::Approved)
            ->whereIn('vocabulary_id', $vocabularies->pluck('id'))->whereIn('product_id', $active)
            ->selectRaw('value_text, count(distinct product_id) as n')->groupBy('value_text')->pluck('n', 'value_text');

        $out = [];
        foreach ($labels as $key => $label) {
            if ((int) ($counts[$key] ?? 0) === 0) {
                continue;
            }
            $out[] = ['key' => $key, 'label' => $label, 'products' => (int) $counts[$key], 'examples' => []];
        }
        usort($out, fn (array $a, array $b): int => $b['products'] <=> $a['products'] ?: strcmp($a['key'], $b['key']));
        $out = array_slice($out, 0, $cap);
        foreach ($out as &$type) {
            $type['examples'] = CatalogProduct::query()->active()
                ->whereIn('id', EnrichmentFact::query()->where('kind', FactKind::Type)->where('status', FactStatus::Approved)->where('value_text', $type['key'])->select('product_id'))
                ->orderBy('id')->limit(self::EXAMPLES)->pluck('title')->map(fn ($t): string => mb_substr((string) $t, 0, 80))->all();
        }
        unset($type);

        return $out;
    }

    /**
     * The pairs as code keeps their shape: both keys of the store's kinds, a kind never with itself, no pair twice, at
     * most the cap per kind in the writer's order. What goes is said.
     *
     * @param  list<array{type: string, partner_type: string, why: string}>  $proposed
     * @param  list<string>  $keys
     * @return array{0: list<array{type: string, partner_type: string, why: string}>, 1: list<array<string, mixed>>}
     */
    public static function shaped(array $proposed, array $keys, int $perType): array
    {
        $known = array_flip($keys);
        $kept = [];
        $removed = [];
        $seen = [];
        $per = [];
        foreach ($proposed as $pair) {
            $why = match (true) {
                ! isset($known[$pair['type']]) || ! isset($known[$pair['partner_type']]) => 'unknown_type',
                $pair['type'] === $pair['partner_type'] => 'same_type',
                isset($seen[self::id($pair)]) => 'repeated',
                ($per[$pair['type']] ?? 0) >= $perType => 'over_cap',
                trim($pair['why']) === '' => 'no_why',
                default => null,
            };
            if ($why !== null) {
                $removed[] = $pair + ['by' => 'code', 'why_removed' => $why];

                continue;
            }
            $seen[self::id($pair)] = true;
            $per[$pair['type']] = ($per[$pair['type']] ?? 0) + 1;
            $kept[] = $pair;
        }

        return [$kept, $removed];
    }

    /**
     * The checker's reply read strictly: it scores the ids code gave and nothing else; of two answers about one pair the
     * harsher one counts.
     *
     * @param  list<string>  $ids
     * @param  list<array<string, mixed>>  $items
     * @return array{0: array<string, array{score: int, verdict: string, reason: string}>, 1: list<string>}
     */
    public static function verdicts(array $ids, array $items): array
    {
        $known = array_flip($ids);
        $verdicts = [];
        $ignored = [];
        foreach ($items as $item) {
            $id = (string) ($item['id'] ?? '');
            if (! isset($known[$id])) {
                $ignored[] = $id;

                continue;
            }
            $now = ['score' => max(0, min(100, (int) ($item['score'] ?? 0))), 'verdict' => ($item['verdict'] ?? 'drop') === 'keep' ? 'keep' : 'drop', 'reason' => mb_substr((string) ($item['reason'] ?? ''), 0, 300)];
            $was = $verdicts[$id] ?? null;
            if ($was === null || $now['verdict'] === 'drop' || $now['score'] < $was['score']) {
                $verdicts[$id] = $was === null ? $now : ['score' => min($was['score'], $now['score']), 'verdict' => $was['verdict'] === 'drop' || $now['verdict'] === 'drop' ? 'drop' : 'keep', 'reason' => $now['reason']];
            }
        }

        return [$verdicts, $ignored];
    }

    /** @param  array{type: string, partner_type: string}  $pair */
    private static function id(array $pair): string
    {
        return $pair['type'].'>'.$pair['partner_type'];
    }

    /**
     * One model call: the cap asked first, the reply held to the prompt's schema by the provider and again in code, and
     * its tokens and cost put on the run. A reply that breaks the schema stops the run; it is not asked again.
     *
     * @param  list<array<string, mixed>>  $calls
     * @return array<string, mixed>
     *
     * @throws ReplySchemaBroken|SpendCapReached|ModelCallFailed
     */
    private function ask(RunContext $run, AgentModel $who, string $role, string $prompt, string $step, array $input, int $maxOutput, string $effort, array &$calls): array
    {
        $version = self::PROMPTS[$prompt];
        $system = (string) file_get_contents(dirname(__DIR__).'/Prompts/'.$prompt.'.v'.$version.'.md');
        $schema = ReplySchema::load($prompt, $version);
        $user = (string) json_encode($input, JSON_UNESCAPED_UNICODE);
        $in = (float) Settings::get("enrichment.goes_with_{$role}_input_usd_per_million");
        $out = (float) Settings::get("enrichment.goes_with_{$role}_output_usd_per_million");

        $this->spend->assertCanSpend(((mb_strlen($system) + mb_strlen($user)) / 3 * $in + $maxOutput * $out) / 1_000_000);

        $reply = $this->models instanceof StructuredChatModel
            ? $this->models->structured($who->provider, $who->model, $system, $user, $schema, $maxOutput, $effort)
            : $this->models->json($who->provider, $who->model, $system, $user, $maxOutput, $effort);

        $cost = $reply->costUsd($in, $out);
        $run->usage($who->provider->value, $who->model, $reply->inputTokens, $reply->outputTokens, $reply->cacheReadTokens, $cost);
        $calls[] = [
            'step' => $step, 'prompt' => $prompt.'.v'.$version, 'provider' => $who->provider->value, 'model' => $who->model,
            'input_tokens' => $reply->inputTokens, 'output_tokens' => $reply->outputTokens,
            'cache_read_tokens' => $reply->cacheReadTokens, 'cache_write_tokens' => $reply->cacheWriteTokens, 'usd' => round($cost, 4),
        ];

        $problems = ReplySchema::problems($reply->data, $schema);
        if ($problems !== []) {
            throw new ReplySchemaBroken($step, $problems);
        }

        return $reply->data;
    }

    /**
     * The run failed after the kinds were counted: what was spent and how far it got is kept, and no map.
     *
     * @param  array<string, mixed>  $record
     * @param  list<string>  $problems
     */
    private function failed(RunContext $run, array $record, array $calls, array $steps, string $key, array $params, array $problems, array $removed, array $proposed): EnrichmentGoesWithMap
    {
        $steps[] = (string) trans($key, $params, 'he');
        $stored = EnrichmentGoesWithMap::query()->create($record + [
            'status' => EnrichmentGoesWithMap::FAILED,
            'proposed' => $proposed,
            'removed' => $removed,
            'calls' => $calls,
            'steps' => $steps,
            'problems' => array_slice($problems, 0, 30),
        ]);
        $run->output(['goes_with_map' => $stored->id, 'status' => $stored->status, 'steps' => $steps, 'calls' => $calls, 'problems' => $stored->problems])
            ->fail($key, $params, mb_substr(implode('; ', $problems), 0, 2000));

        return $stored;
    }

    /** @param  list<array<string, mixed>>  $calls */
    private static function usd(array $calls): string
    {
        return number_format(array_sum(array_column($calls, 'usd')), 2, '.', '');
    }
}
