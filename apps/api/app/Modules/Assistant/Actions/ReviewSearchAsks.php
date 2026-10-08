<?php

namespace App\Modules\Assistant\Actions;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\AgentModel;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\SpendCapReached;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Assistant\Models\AssistantAskReview;
use App\Modules\Assistant\Models\AssistantSearchAsk;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Runs\Models\Run;
use App\Modules\Tenancy\Models\Shop;

/**
 * Once a day: how the assistant did with the questions asked in the search box yesterday.
 *
 * 1. Code gathers yesterday's questions with what was shown, said, picked and done, and counts
 *    them. No questions: a quiet day, no model.
 * 2. A model from another family than the one that answered scores each from 1 to 5, and writes
 *    a few plain sentences and up to five improvements for the shop manager.
 * 3. Code keeps only scores for questions it gave, and turns their average into a score out of
 *    100. The report is saved once per day; asking again for the same day changes nothing.
 */
final class ReviewSearchAsks
{
    public const AGENT = 'assistant.ask_reviewer';

    public const ACTION = 'assistant.review_asks';

    public const PROMPT_VERSION = 1;

    private const MAX_IMPROVEMENTS = 5;

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
        private readonly ChatModel $models,
        private readonly SpendGuard $spend,
    ) {}

    public function handle(string $shopId, ?string $day = null, RunTrigger $trigger = RunTrigger::Manual): Run
    {
        $day ??= now()->subDay()->toDateString();

        return $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            trigger: $trigger,
            input: ['day' => $day],
            work: fn (RunContext $run) => $this->tenant->run($shopId, fn () => $this->review($run, $shopId, $day)),
        );
    }

    private function review(RunContext $run, string $shopId, string $day): void
    {
        if (AssistantAskReview::query()->whereDate('day', $day)->whereIn('status', [AssistantAskReview::REVIEWED, AssistantAskReview::QUIET])->exists()) {
            $run->output(['day' => $day, 'skipped' => 'done'])->summary('assistant::runs.review_done', ['day' => $day]);

            return;
        }

        $asks = AssistantSearchAsk::query()->whereDate('day', $day)->orderBy('created_at')->orderBy('id')->limit((int) Settings::get('assistant.review_max_asks', $shopId))->get();
        $counts = [
            'asks' => $asks->count(),
            'answered' => $asks->where('outcome', AssistantAnswer::ANSWERED)->count(),
            'no_match' => $asks->whereIn('outcome', ['no_match', 'no_info'])->count(),
            'whatsapp_shown' => $asks->where('whatsapp_shown', true)->count(),
            'whatsapp_clicked' => $asks->where('whatsapp_clicked', true)->count(),
            'picked' => $asks->filter(fn (AssistantSearchAsk $a): bool => (array) $a->picked !== [])->count(),
        ];

        if ($asks->isEmpty()) {
            $this->save($day, AssistantAskReview::QUIET, null, null, [], [], $counts, null, $run);
            $run->output(['day' => $day, 'asks' => 0])->summary('assistant::runs.review_quiet', ['day' => $day]);

            return;
        }

        $reviewer = AgentModel::from('assistant.review_provider', 'assistant.review_model');
        $writer = AgentModel::provider('assistant.answer_provider');

        if ($reviewer->provider->family() === $writer->family()) {
            $run->fail('assistant::runs.review_same_family', ['family' => $writer->label()]);

            return;
        }

        $answers = AssistantAnswer::query()->whereIn('id', $asks->pluck('answer_id')->filter()->unique()->values())->get(['id', 'answer'])->keyBy('id');
        $titles = CatalogProduct::query()->whereIn('external_id', $asks->pluck('products')->flatten()->unique()->values())->pluck('title', 'external_id');
        $byRef = [];
        $items = [];

        foreach ($asks->values() as $i => $ask) {
            $ref = 'a'.($i + 1);
            $byRef[$ref] = $ask;
            $items[] = [
                'id' => $ref,
                'question' => $ask->question,
                'shown' => array_values(array_filter(array_map(fn ($id) => $titles[$id] ?? null, array_slice((array) $ask->products, 0, 8)))),
                'answer' => (string) ($answers->get($ask->answer_id)?->answer ?? ''),
                'picks' => array_map(fn (array $p): string => $p['title'].' — '.$p['why'], (array) $ask->picks),
                'outcome' => $ask->outcome,
                'whatsapp_offered' => $ask->whatsapp_shown,
                'whatsapp_clicked' => $ask->whatsapp_clicked,
                'opened_picks' => array_values(array_filter(array_map(fn ($id) => $titles[$id] ?? null, (array) $ask->picked))),
            ];
        }

        $shopName = (string) (Shop::query()->whereKey($shopId)->value('name') ?? '');
        $user = (string) json_encode(['shop' => $shopName, 'items' => $items], JSON_UNESCAPED_UNICODE);
        $system = (string) file_get_contents(__DIR__.'/../Prompts/review_asks.v'.self::PROMPT_VERSION.'.md');
        $maxOutput = (int) Settings::get('assistant.review_max_output_tokens');
        $in = (float) Settings::get('assistant.review_input_usd_per_million');
        $out = (float) Settings::get('assistant.review_output_usd_per_million');

        try {
            $this->spend->assertCanSpend(((mb_strlen($system) + mb_strlen($user)) / 2 * $in + $maxOutput * $out) / 1_000_000);
            $reply = $this->models->json($reviewer->provider, $reviewer->model, $system, $user, $maxOutput);
            $run->usage($reviewer->provider->value, $reviewer->model, $reply->inputTokens, $reply->outputTokens, 0, $reply->costUsd($in, $out));
        } catch (SpendCapReached $e) {
            $run->fail('assistant::runs.spend_cap', [], $e->getMessage());

            return;
        } catch (ModelCallFailed $e) {
            $run->fail('assistant::runs.model_failed', ['reason' => $e->reason], $e->getMessage());

            return;
        }

        // Code keeps only scores for questions it gave, each from 1 to 5.
        $scored = [];

        foreach ((array) ($reply->data['items'] ?? []) as $item) {
            $ref = is_array($item) ? (string) ($item['id'] ?? '') : '';
            $score = is_array($item) ? (int) ($item['score'] ?? 0) : 0;

            if (isset($byRef[$ref]) && $score >= 1 && $score <= 5 && ! isset($scored[$ref])) {
                $scored[$ref] = ['ask_id' => $byRef[$ref]->id, 'score' => $score, 'note' => mb_substr(trim((string) ($item['note'] ?? '')), 0, 200)];
            }
        }

        $score = $scored === [] ? null : (int) round(array_sum(array_column($scored, 'score')) / count($scored) / 5 * 100);
        $improvements = array_slice(array_values(array_filter(array_map(fn ($line): string => mb_substr(trim((string) $line), 0, 200), (array) ($reply->data['improvements'] ?? [])))), 0, self::MAX_IMPROVEMENTS);
        $summary = mb_substr(trim((string) ($reply->data['summary'] ?? '')), 0, 1200);

        $this->save($day, AssistantAskReview::REVIEWED, $score, $summary === '' ? null : $summary, $improvements, array_values($scored), $counts, $reviewer->provider->value.' · '.$reviewer->model, $run);
        $run->output(['day' => $day, 'asks' => $counts['asks'], 'scored' => count($scored), 'score' => $score])
            ->summary('assistant::runs.review_done_score', ['day' => $day, 'asks' => (string) $counts['asks'], 'score' => (string) ($score ?? '—')]);
    }

    /**
     * @param  list<string>  $improvements
     * @param  list<array{ask_id: string, score: int, note: string}>  $items
     * @param  array<string, int>  $counts
     */
    private function save(string $day, string $status, ?int $score, ?string $summary, array $improvements, array $items, array $counts, ?string $by, RunContext $run): void
    {
        // Found by its date, not its stored form: SQLite keeps a time with the date.
        $existing = AssistantAskReview::query()->whereDate('day', $day)->first();
        ($existing ?? new AssistantAskReview(['shop_id' => $this->tenant->require(), 'day' => $day]))->fill([
            'status' => $status,
            'score' => $score,
            'summary' => $summary,
            'improvements' => $improvements,
            'items' => $items,
            'counts' => $counts,
            'reviewed_by' => $by,
            'run_id' => $run->runId,
        ])->save();
    }
}
