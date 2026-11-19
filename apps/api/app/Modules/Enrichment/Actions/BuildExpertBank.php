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
use App\Modules\Enrichment\Models\EnrichmentExpertBank;
use App\Modules\Enrichment\Scanning\BankEvidence;
use App\Modules\Enrichment\Scanning\CatalogueFacts;
use App\Modules\Enrichment\Scanning\ExpertBankGate;
use App\Modules\Enrichment\Scanning\ExpertBankShape;
use App\Modules\Enrichment\Support\ReplySchema;
use App\Modules\Enrichment\Support\ReplySchemaBroken;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Models\Run;
use App\Modules\Tenancy\Models\Shop;
use Generator;

/**
 * A store's attribute bank written for it alone, with no ready vocabulary read anywhere on the way. A dry run: it ends
 * in a report (EnrichmentExpertBank) and changes nothing the store is read or searched with.
 *
 * 1. Facts, in code (CatalogueFacts): a sample of products, the store's own fields, the words and phrases its
 *    products repeat, its language, how many products there are.
 * 2. The expert (one family) writes the bank from those facts: types, attributes and values, each with the store's
 *    own words that say it.
 * 3. Code measures every word on the store's products (BankEvidence) and takes out what reads none.
 * 4. One correction round: the measurement goes back to the expert once; code measures again and again takes out
 *    what reads nothing. There is no second round.
 * 5. The checker (another family, checked in code before any call) scores every type, attribute and value. It can
 *    take out and nothing else: what it adds or renames is ignored, what it leaves unscored is dropped.
 * 6. The gate, in code (ExpertBankGate): an item stays only with evidence and a score at the threshold; a value on too
 *    few products, a value nearly every product of its attribute holds, a value that says it is not known, and an
 *    attribute with too few values or products are no filters; the bank as a whole passes on the shares measured on
 *    this store.
 *
 * Within one attribute, the longest phrase wins (LongestPhrase), here and in the code reading of an activated bank.
 * A bank that passed becomes the store's vocabulary only when a person activates it (ActivateExpertBank).
 *
 * A reply that breaks its schema fails the run: nothing is mended and nothing is asked again. Every call asks
 * SpendGuard first and is recorded on the run with its tokens and cost, and each step says one line.
 */
final class BuildExpertBank
{
    public const AGENT = 'enrichment.expert_bank';

    public const ACTION = 'enrichment.build_expert_bank';

    public const FEATURE = 'enrichment.expert_bank';

    /** The code's own version, part of the fingerprint: a change in how facts are counted or the bank measured asks again. */
    public const VERSION = 2;

    /** The released version each prompt is sent at; a released one is never edited. */
    public const PROMPTS = ['expert_bank' => 2, 'expert_bank_check' => 1];

    private const WRITER_TOKENS = 20000;

    private const CHECKER_TOKENS = 12000;

    /** Items one checker call scores. Each call is given the whole bank. */
    public const CHECK_BATCH = 120;

    /** Passes of the least-evidence rules before the bank is taken as settled; each pass only takes out. */
    private const SETTLE = 6;

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
        private readonly ChatModel $models,
        private readonly SpendGuard $spend,
    ) {}

    /** @return array{run: Run, bank: ?EnrichmentExpertBank} the stored report; null when nothing was asked */
    public function handle(string $shopId, bool $force = false): array
    {
        $bank = null;
        $run = $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            input: ['force' => $force],
            work: function (RunContext $run) use ($shopId, $force, &$bank): void {
                $bank = $this->tenant->run($shopId, fn (): ?EnrichmentExpertBank => $this->build($run, $shopId, $force));
            },
        );

        return ['run' => $run, 'bank' => $bank];
    }

    private function build(RunContext $run, string $shopId, bool $force): ?EnrichmentExpertBank
    {
        if (! Features::enabled(self::FEATURE, $shopId)) {
            $run->fail('enrichment::runs.expert_bank_off');

            return null;
        }

        $writer = AgentModel::from('enrichment.expert_bank_writer_provider', 'enrichment.expert_bank_writer_model');
        $checker = AgentModel::from('enrichment.expert_bank_checker_provider', 'enrichment.expert_bank_checker_model');

        // What one family writes, the other checks. Decided here, before a product is read or a model asked.
        if ($writer->provider->family() === $checker->provider->family()) {
            $run->fail('enrichment::runs.expert_bank_same_family', [], 'same family: '.$writer->provider->value.' / '.$checker->provider->value);

            return null;
        }

        $limits = [
            'min_score' => (int) Settings::get('enrichment.expert_bank_min_score', $shopId),
            'min_products' => (int) Settings::get('enrichment.expert_bank_min_products', $shopId),
            'min_attribute_share' => (int) Settings::get('enrichment.expert_bank_min_attribute_share', $shopId) / 100,
            'min_type_share' => (int) Settings::get('enrichment.expert_bank_min_type_share', $shopId) / 100,
            'min_filterable_share' => (int) Settings::get('enrichment.expert_bank_min_filterable_share', $shopId) / 100,
            'values_per_product' => (int) Settings::get('enrichment.expert_bank_values_per_product', $shopId),
            'max_value_share' => (int) Settings::get('enrichment.expert_bank_max_value_share', $shopId) / 100,
        ];

        // 1. Facts, in code. No ready vocabulary is read.
        ['facts' => $facts, 'texts' => $texts] = CatalogueFacts::of(
            $this->products((int) Settings::get('enrichment.expert_bank_max_products', $shopId)),
            (int) Settings::get('enrichment.expert_bank_sample', $shopId),
            (string) Shop::query()->whereKey($shopId)->value('content_locale'),
        );
        if ($texts === []) {
            $run->fail('enrichment::runs.expert_bank_nothing');

            return null;
        }

        $steps = [];
        $say = function (string $key, array $params = []) use (&$steps, $run): void {
            // One line a step, in Hebrew, on the run as it goes.
            $steps[] = (string) trans('enrichment::runs.'.$key, $params, 'he');
            $run->output(['steps' => $steps]);
        };
        $say('expert_bank_step_facts', ['products' => $facts['products'], 'language' => $facts['language'], 'sample' => count($facts['sample']), 'words' => count($facts['words']) + count($facts['phrases'])]);

        $fingerprint = hash('sha256', (string) json_encode([
            self::VERSION, self::PROMPTS, $writer->provider->value, $writer->model, $checker->provider->value, $checker->model, $limits, $facts,
        ], JSON_UNESCAPED_UNICODE));
        $last = EnrichmentExpertBank::query()->where('fingerprint', $fingerprint)->where('status', '!=', EnrichmentExpertBank::FAILED)->latest()->first();
        if ($last !== null && ! $force) {
            // Never asked twice: the same facts, prompts, models and thresholds were answered already.
            $run->output(['expert_bank' => $last->id, 'unchanged' => true])->summary('enrichment::runs.expert_bank_unchanged', ['id' => $last->id]);

            return $last;
        }

        $record = [
            'shop_id' => $shopId,
            'fingerprint' => $fingerprint,
            'products' => $facts['products'],
            'writer' => $writer->provider->value.' / '.$writer->model,
            'checker' => $checker->provider->value.' / '.$checker->model,
            'facts' => $facts,
            'run_id' => $run->runId,
        ];
        $calls = [];
        $removed = [];
        $drafts = [];
        $evidence = new BankEvidence($texts);
        $frequent = $facts['words'] + $facts['phrases'];
        $measure = fn (array $bank): array => $evidence->measure($bank, $frequent, $limits['values_per_product']);

        try {
            // 2 to 4. The expert writes, code measures; once more with the measurement, and no more.
            $bank = ['types' => [], 'attributes' => []];
            $measured = null;
            foreach ([1, 2] as $round) {
                $before = count($calls);
                $input = ['round' => $round, 'facts' => $facts] + ($round === 1 ? [] : [
                    'bank' => $bank,
                    'measured' => $measured,
                    'removed' => array_map(fn (array $r): array => array_intersect_key($r, array_flip(['id', 'kind', 'label', 'why'])), $removed),
                ]);
                $reply = $this->ask($run, $writer, 'writer', 'expert_bank', 'round_'.$round, $input, self::WRITER_TOKENS, 'medium', $calls);
                [$bank, $unformed] = ExpertBankShape::fromReply($reply, 'round_'.$round);
                // What no product says is taken out, always: a word, then the value or the type left with none.
                [$bank, $unseen] = ExpertBankGate::unseen($bank, $measure($bank), 'round_'.$round);
                // A value that says it is not known ("not confirmed", "N/A") is no choice, however many products say it.
                [$bank, $unchosen] = ExpertBankGate::notChoices($bank, 'round_'.$round);
                $unseen = [...$unseen, ...$unchosen];
                $measured = $measure($bank);
                $removed = [...$removed, ...$unformed, ...$unseen];
                $drafts[] = ['round' => $round, 'bank' => $bank, 'type_share' => $measured['type_share'], 'filterable_share' => $measured['filterable_share'], 'notes' => array_slice(array_map('strval', (array) ($reply['notes'] ?? [])), 0, 5)];
                $say('expert_bank_step_round', [
                    'round' => $round, 'model' => $writer->model, 'types' => count($bank['types']), 'attributes' => count($bank['attributes']),
                    'values' => self::values($bank), 'removed' => count($unformed) + count($unseen), 'usd' => self::usd(array_slice($calls, $before)),
                ]);
            }

            // 5. The other family scores every item. It can take out, and nothing else.
            $ids = ExpertBankShape::ids($bank);
            $before = count($calls);
            $items = [];
            $checklist = ExpertBankGate::checklist($bank, $measured, $limits['min_products']);
            foreach (array_chunk($ids, self::CHECK_BATCH) as $part => $chunk) {
                $reply = $this->ask($run, $checker, 'checker', 'expert_bank_check', 'check_'.($part + 1), [
                    'facts' => $facts, 'bank' => $bank, 'measured' => $measured, 'checklist' => $checklist, 'score' => $chunk,
                ], self::CHECKER_TOKENS, 'medium', $calls);
                $items = [...$items, ...array_values((array) ($reply['items'] ?? []))];
            }
            [$verdicts, $ignored] = ExpertBankGate::verdicts($ids, $items, $limits['min_score']);
            [$bank, $dropped] = ExpertBankGate::checked($bank, $verdicts);
            $removed = [...$removed, ...$dropped];
            $say('expert_bank_step_check', ['model' => $checker->model, 'scored' => count($ids), 'dropped' => count($dropped), 'ignored' => count($ignored), 'usd' => self::usd(array_slice($calls, $before))]);
        } catch (ReplySchemaBroken $e) {
            return $this->failed($run, $record, $calls, $steps, 'enrichment::runs.expert_bank_schema', ['step' => $e->step], $e->problems, $removed, $drafts);
        } catch (SpendCapReached $e) {
            return $this->failed($run, $record, $calls, $steps, 'enrichment::runs.expert_bank_spend_cap', [], [$e->getMessage()], $removed, $drafts);
        } catch (ModelCallFailed $e) {
            return $this->failed($run, $record, $calls, $steps, 'enrichment::runs.expert_bank_model_failed', ['reason' => $e->reason], [$e->reason], $removed, $drafts);
        }

        // 6. The gate, in code: the least evidence a filter needs, until nothing more goes.
        $thinned = 0;
        for ($pass = 0; $pass < self::SETTLE; $pass++) {
            [$bank, $thin] = ExpertBankGate::thin($bank, $measure($bank), $limits['min_products'], $limits['min_attribute_share'], $limits['max_value_share']);
            if ($thin === []) {
                break;
            }
            $removed = [...$removed, ...$thin];
            $thinned += count($thin);
        }
        $measured = $measure($bank);
        $gate = ExpertBankGate::bank($bank, $measured, $limits['min_type_share'], $limits['min_filterable_share']) + ['limits' => $limits];
        $say('expert_bank_step_gate', ['removed' => $thinned, 'types' => count($bank['types']), 'attributes' => count($bank['attributes']), 'values' => self::values($bank)]);

        $params = [
            'types' => count($bank['types']), 'attributes' => count($bank['attributes']), 'values' => self::values($bank), 'removed' => count($removed),
            'type_share' => (int) round($gate['type_share'] * 100), 'filterable_share' => (int) round($gate['filterable_share'] * 100), 'usd' => self::usd($calls),
        ];
        $summary = $gate['passed'] ? 'enrichment::runs.expert_bank_passed' : 'enrichment::runs.expert_bank_held';
        $steps[] = (string) trans($summary, $params, 'he');

        $stored = EnrichmentExpertBank::query()->create($record + [
            'status' => $gate['passed'] ? EnrichmentExpertBank::PASSED : EnrichmentExpertBank::HELD,
            'bank' => ExpertBankShape::withPatterns($bank),
            'drafts' => $drafts,
            'measurement' => $measured,
            'review' => ['threshold' => $limits['min_score'], 'items' => $verdicts, 'ignored' => $ignored, 'checklist' => $checklist],
            'removed' => $removed,
            'gate' => $gate,
            'calls' => $calls,
            'steps' => $steps,
        ]);

        $run->output(['expert_bank' => $stored->id, 'status' => $stored->status, 'gate' => $gate, 'steps' => $steps, 'calls' => $calls])->summary($summary, $params);

        return $stored;
    }

    /**
     * The store's products in a fixed order (their ids in the store). A catalogue over the cap is read in part: the
     * same products every time, chosen by a hash of their ids.
     *
     * @return Generator<int, CatalogProduct>
     */
    private function products(int $cap): Generator
    {
        $ids = [];
        foreach (CatalogProduct::query()->active()->orderBy('id')->toBase()->get(['id', 'external_id']) as $row) {
            $ids[] = [crc32((string) $row->external_id), (string) $row->external_id, (string) $row->id];
        }
        if (count($ids) > $cap) {
            sort($ids);
            $ids = array_slice($ids, 0, $cap);
        }
        usort($ids, fn (array $a, array $b): int => [$a[1], $a[2]] <=> [$b[1], $b[2]]);

        foreach (array_chunk(array_column($ids, 2), 300) as $chunk) {
            $found = CatalogProduct::query()->whereIn('id', $chunk)->get()->keyBy('id');
            foreach ($chunk as $id) {
                if (isset($found[$id])) {
                    yield $found[$id];
                }
            }
        }
    }

    /**
     * One model call: the cap asked first, the reply held to the prompt's schema by the provider and again in code,
     * and its tokens and cost put on the run. A reply that breaks the schema stops the run; it is not asked again.
     *
     * @param  list<array<string, mixed>>  $calls  every call so far, added to
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
        $in = (float) Settings::get("enrichment.expert_bank_{$role}_input_usd_per_million");
        $out = (float) Settings::get("enrichment.expert_bank_{$role}_output_usd_per_million");

        $this->spend->assertCanSpend(((mb_strlen($system) + mb_strlen($user)) / 3 * $in + $maxOutput * $out) / 1_000_000);

        // Anthropic thinks on its own at the effort asked (adaptive, no prefill): the driver's own rule.
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
     * The run failed after facts were counted: what was spent and how far it got is kept, and no bank.
     *
     * @param  array<string, mixed>  $record
     * @param  list<string>  $problems
     */
    private function failed(RunContext $run, array $record, array $calls, array $steps, string $key, array $params, array $problems, array $removed, array $drafts): EnrichmentExpertBank
    {
        $steps[] = (string) trans($key, $params, 'he');
        $stored = EnrichmentExpertBank::query()->create($record + [
            'status' => EnrichmentExpertBank::FAILED,
            'drafts' => $drafts,
            'removed' => $removed,
            'calls' => $calls,
            'steps' => $steps,
            'problems' => array_slice($problems, 0, 30),
        ]);
        $run->output(['expert_bank' => $stored->id, 'status' => $stored->status, 'steps' => $steps, 'calls' => $calls, 'problems' => $stored->problems])
            ->fail($key, $params, mb_substr(implode('; ', $problems), 0, 2000));

        return $stored;
    }

    private static function values(array $bank): int
    {
        return array_sum(array_map(fn (array $attribute): int => count($attribute['values']), $bank['attributes']));
    }

    /** @param  list<array<string, mixed>>  $calls */
    private static function usd(array $calls): string
    {
        return number_format(array_sum(array_column($calls, 'usd')), 2, '.', '');
    }
}
