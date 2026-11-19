<?php

namespace App\Modules\Search\Actions;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\AgentModel;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\SpendCapReached;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Ai\Contracts\StructuredChatModel;
use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Models\Run;
use App\Modules\Search\Models\SearchFilterLayout;
use App\Modules\Search\Models\SearchIndex;
use App\Modules\Search\Support\FilterLayoutGate;
use App\Modules\Search\Support\FilterScreen;
use App\Modules\Search\Support\ReplySchema;
use App\Modules\Search\Support\ReplySchemaBroken;
use App\Modules\Tenancy\Models\Shop;

/**
 * The filter screen a store's shoppers see, arranged: an advanced model with a critical eye proposes, a model of the
 * other family scores, and code applies only an order and hiding. Nothing is renamed, added or merged.
 *
 * 1. The screen, in code (FilterScreen): every filter the store's search index offers, its source, each value with
 *    its products, how many products each filter covers, and what filters and values share; the store's language
 *    and a line about its catalogue.
 * 2. The writer (search.filter_layout_writer_*) returns the order of the filters, value orders where count order is
 *    wrong for shoppers, filters to hide (a reason of a fixed set) and values to move out of a filter that mixes kinds
 *    into another that already has them. Code keeps only what is on the screen (FilterLayoutGate::proposal).
 * 3. The checker (search.filter_layout_checker_*), of another family, checked in code before any call, scores each
 *    change 0 to 100 and can only veto. Code applies an item agreed at the threshold or above, the surest first, and
 *    never leaves fewer filters than the store's minimum (FilterLayoutGate::apply).
 * 4. Stored (SearchFilterLayout) with the proposal, the scores, what was applied and refused, tokens and cost. The
 *    search index applies the newest one that did not fail while the flag is on (FilterLayouts).
 *
 * On request only (`search layout {shop}`), never on a schedule. A reply that breaks its schema fails the run: nothing
 * is mended and nothing is asked again. Every call asks SpendGuard first and is recorded on the run, and each step says
 * one Hebrew line.
 */
final class ArrangeFilters
{
    public const AGENT = 'search.filter_layout';

    public const ACTION = 'search.arrange_filters';

    public const FEATURE = 'search.filter_layout';

    /** The code's own version, part of the fingerprint: a change in how the screen is read or the layout applied asks again. */
    public const VERSION = 1;

    /** The released version each prompt is sent at; a released one is never edited. */
    public const PROMPTS = ['filter_layout' => 1, 'filter_layout_check' => 1];

    private const WRITER_TOKENS = 16000;

    private const CHECKER_TOKENS = 8000;

    /** The store's largest categories the models are told of, as a line about its trade. */
    private const TOP_CATEGORIES = 10;

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
        private readonly ChatModel $models,
        private readonly SpendGuard $spend,
    ) {}

    /** @return array{run: Run, layout: ?SearchFilterLayout} the stored layout; null when nothing was asked */
    public function handle(string $shopId, bool $force = false): array
    {
        $layout = null;
        $run = $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            input: ['force' => $force],
            work: function (RunContext $run) use ($shopId, $force, &$layout): void {
                $layout = $this->tenant->run($shopId, fn (): ?SearchFilterLayout => $this->arrange($run, $shopId, $force));
            },
        );

        return ['run' => $run, 'layout' => $layout];
    }

    /** The screen as code reads it from the store's index now; null when it has no index. */
    public static function screenOf(string $shopId): ?FilterScreen
    {
        $json = SearchIndex::query()->where('shop_id', $shopId)->value('items');
        $index = is_string($json) ? json_decode($json, true) : null;

        return is_array($index) ? new FilterScreen($index) : null;
    }

    private function arrange(RunContext $run, string $shopId, bool $force): ?SearchFilterLayout
    {
        if (! Features::enabled(self::FEATURE, $shopId)) {
            $run->fail('search::runs.layout_off');

            return null;
        }

        $writer = AgentModel::from('search.filter_layout_writer_provider', 'search.filter_layout_writer_model');
        $checker = AgentModel::from('search.filter_layout_checker_provider', 'search.filter_layout_checker_model');

        // What one family writes, the other checks. Decided here, before the index is read or a model asked.
        if ($writer->provider->family() === $checker->provider->family()) {
            $run->fail('search::runs.layout_same_family', ['family' => $writer->provider->value], 'same family: '.$writer->provider->value.' / '.$checker->provider->value);

            return null;
        }

        $limits = [
            'min_score' => (int) Settings::get('search.filter_layout_min_score', $shopId),
            'min_filters' => (int) Settings::get('search.filter_layout_min_filters', $shopId),
            'max_values' => (int) Settings::get('search.filter_layout_max_values', $shopId),
            'duplicate_min_shared' => (int) Settings::get('search.filter_layout_duplicate_min_shared', $shopId) / 100,
            'duplicate_min_same_split' => (int) Settings::get('search.filter_layout_duplicate_min_same_split', $shopId) / 100,
            'move_min_cover' => (int) Settings::get('search.filter_layout_move_min_cover', $shopId) / 100,
        ];

        // 1. The screen, in code.
        $screen = self::screenOf($shopId);
        if ($screen === null) {
            $run->fail('search::runs.layout_no_index');

            return null;
        }
        if (count($screen->filters) < 2) {
            $run->fail('search::runs.layout_nothing', ['filters' => count($screen->filters)]);

            return null;
        }
        $categories = CatalogCategory::query()->active()->where('product_count', '>', 0)->orderByDesc('product_count')->orderBy('name')->limit(self::TOP_CATEGORIES)
            ->get(['name', 'product_count'])->map(fn (CatalogCategory $c): array => ['name' => (string) $c->name, 'products' => (int) $c->product_count])->all();
        $input = $screen->forModel(
            (string) (Shop::query()->whereKey($shopId)->value('content_locale') ?: 'he'),
            ['products' => $screen->products, 'top_categories' => $categories],
            $limits['max_values'],
            $limits['min_filters'],
        );

        $steps = [];
        $say = function (string $key, array $params = []) use (&$steps, $run): void {
            // One line a step, in Hebrew, on the run as it goes.
            $steps[] = (string) trans('search::runs.'.$key, $params, 'he');
            $run->output(['steps' => $steps]);
        };
        $say('layout_step_screen', ['filters' => count($screen->filters), 'values' => $screen->valueCount(), 'products' => $screen->products, 'pairs' => count($screen->filterOverlaps)]);

        $fingerprint = hash('sha256', (string) json_encode([
            self::VERSION, self::PROMPTS, $writer->provider->value, $writer->model, $checker->provider->value, $checker->model, $limits, $input,
        ], JSON_UNESCAPED_UNICODE));
        $last = SearchFilterLayout::query()->where('fingerprint', $fingerprint)->where('status', '!=', SearchFilterLayout::FAILED)->latest()->first();
        if ($last !== null && ! $force) {
            // Never asked twice: the same screen, prompts, models and limits were answered already.
            $run->output(['filter_layout' => $last->id, 'unchanged' => true])->summary('search::runs.layout_unchanged', ['id' => $last->id]);

            return $last;
        }

        $record = [
            'shop_id' => $shopId,
            'fingerprint' => $fingerprint,
            'writer' => $writer->provider->value.' / '.$writer->model,
            'checker' => $checker->provider->value.' / '.$checker->model,
            'screen' => $input,
            'run_id' => $run->runId,
        ];
        $calls = [];
        $proposal = null;
        $items = [];
        $ignored = [];
        $verdicts = [];
        $unknown = [];

        try {
            // 2. The writer proposes; code keeps what is on the screen.
            $proposal = $this->ask($run, $writer, 'writer', 'filter_layout', 'write', $input, self::WRITER_TOKENS, 'high', $calls);
            [$items, $ignored] = FilterLayoutGate::proposal($proposal, $screen, $limits);
            $count = fn (string $kind): int => count(array_filter($items, fn (array $i): bool => $i['kind'] === $kind));
            $say('layout_step_write', ['model' => $writer->model, 'order' => $count('order'), 'values' => $count('values'), 'hides' => $count('hide'), 'moves' => $count('move'),
                'ignored' => count($ignored), 'usd' => self::usd($calls)]);

            // 3. The other family scores every change. It can veto, and nothing else.
            if ($items !== []) {
                $before = count($calls);
                $reply = $this->ask($run, $checker, 'checker', 'filter_layout_check', 'check', $input + ['items' => $items], self::CHECKER_TOKENS, 'medium', $calls);
                [$verdicts, $unknown] = FilterLayoutGate::verdicts(array_column($items, 'id'), array_values((array) ($reply['items'] ?? [])), $limits['min_score']);
                $agreed = count(array_filter($verdicts, fn (array $v): bool => $v['applied']));
                $say('layout_step_check', ['model' => $checker->model, 'scored' => count($items), 'agreed' => $agreed, 'held' => count($items) - $agreed, 'usd' => self::usd(array_slice($calls, $before))]);
            }
        } catch (ReplySchemaBroken $e) {
            return $this->failed($run, $record, $calls, $steps, $proposal, $items, 'search::runs.layout_schema', ['step' => $e->step], $e->problems);
        } catch (SpendCapReached $e) {
            return $this->failed($run, $record, $calls, $steps, $proposal, $items, 'search::runs.layout_spend_cap', [], [$e->getMessage()]);
        } catch (ModelCallFailed $e) {
            return $this->failed($run, $record, $calls, $steps, $proposal, $items, 'search::runs.layout_model_failed', ['reason' => $e->reason], [$e->reason]);
        }

        // 4. Code applies what passed, where the screen stays whole.
        [$applied, $refused] = FilterLayoutGate::apply($items, $verdicts, $screen, $limits['min_filters']);
        $say('layout_step_apply', ['hidden' => count($applied['hide']), 'values' => count($applied['values']), 'moves' => array_sum(array_map('count', $applied['moves'])),
            'order' => $applied['order'] === [] ? 0 : 1, 'refused' => count($refused)]);

        $empty = FilterLayoutGate::empty($applied);
        $params = [
            'hidden' => count($applied['hide']), 'values' => count($applied['values']), 'moves' => array_sum(array_map('count', $applied['moves'])),
            'order' => $applied['order'] === [] ? 0 : 1, 'held' => count($items) - count(array_filter($verdicts, fn (array $v): bool => $v['applied'])) + count($refused), 'usd' => self::usd($calls),
        ];
        $summary = $empty ? 'search::runs.layout_kept' : 'search::runs.layout_applied';
        $steps[] = (string) trans($summary, $params, 'he');

        $stored = SearchFilterLayout::query()->create($record + [
            'status' => $empty ? SearchFilterLayout::KEPT : SearchFilterLayout::APPLIED,
            'proposal' => $proposal,
            'items' => $items,
            'review' => ['threshold' => $limits['min_score'], 'verdicts' => $verdicts, 'ignored' => $unknown],
            'applied' => $applied,
            'refused' => ['ignored' => $ignored, 'refused' => $refused],
            'calls' => $calls,
            'steps' => $steps,
        ] + self::totals($calls));

        $run->output(['filter_layout' => $stored->id, 'status' => $stored->status, 'applied' => $applied, 'steps' => $steps, 'calls' => $calls])->summary($summary, $params);

        return $stored;
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
        $user = (string) json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $in = (float) Settings::get("search.filter_layout_{$role}_input_usd_per_million");
        $out = (float) Settings::get("search.filter_layout_{$role}_output_usd_per_million");

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
     * The run failed after the screen was read: what was spent and how far it got is kept, and no layout is applied
     * (the index goes on with the one before, if any).
     *
     * @param  array<string, mixed>  $record
     * @param  list<string>  $problems
     */
    private function failed(RunContext $run, array $record, array $calls, array $steps, ?array $proposal, array $items, string $key, array $params, array $problems): SearchFilterLayout
    {
        $steps[] = (string) trans($key, $params, 'he');
        $stored = SearchFilterLayout::query()->create($record + [
            'status' => SearchFilterLayout::FAILED,
            'proposal' => $proposal,
            'items' => $items,
            'calls' => $calls,
            'steps' => $steps,
            'problems' => array_slice($problems, 0, 30),
        ] + self::totals($calls));
        $run->output(['filter_layout' => $stored->id, 'status' => $stored->status, 'steps' => $steps, 'calls' => $calls, 'problems' => $stored->problems])
            ->fail($key, $params, mb_substr(implode('; ', $problems), 0, 2000));

        return $stored;
    }

    /** @return array{input_tokens: int, output_tokens: int, cost_usd: float} */
    private static function totals(array $calls): array
    {
        return [
            'input_tokens' => array_sum(array_column($calls, 'input_tokens')),
            'output_tokens' => array_sum(array_column($calls, 'output_tokens')),
            'cost_usd' => round(array_sum(array_column($calls, 'usd')), 4),
        ];
    }

    /** @param  list<array<string, mixed>>  $calls */
    private static function usd(array $calls): string
    {
        return number_format(array_sum(array_column($calls, 'usd')), 2, '.', '');
    }
}
