<?php

namespace App\Modules\Assistant\Actions;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\SpendCapReached;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Assistant\Support\Subject;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Retrieval\Contracts\Passages;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Runs\Models\Run;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Support\Facades\Cache;

/**
 * A draft for the team's answer to a shopper's question, on the questions screen. The team presses
 * the button, with or without a few words of its own in the box; code gathers the page the
 * question was asked on and the store's text nearest it; a model writes the draft from that only
 * (the team's words are true); a model of the other family checks it; code flags any number that
 * is in none of it. What nothing says stays "[לבדוק: ...]" for the team, and the screen will not
 * publish an answer that still has one. Nothing reaches shoppers before the team publishes.
 *
 * The same input is never asked twice: the draft is kept by its fingerprint for a week.
 */
final class DraftTeamAnswer
{
    public const AGENT = 'assistant.team_helper';

    public const ACTION = 'assistant.draft_team_answer';

    public const PROMPT_VERSION = 1;

    /** Pieces of the store's text the writer gets. */
    private const PASSAGES = 6;

    private const MAX_PASSAGE = 1000;

    private const MAX_NOTES = 600;

    private const MAX_ANSWER = 1200;

    private const MAX_PROBLEMS = 5;

    private const CHECK_OUTPUT_TOKENS = 500;

    private const KEEP_DAYS = 7;

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly ChatModel $chat,
        private readonly SpendGuard $spend,
        private readonly TenantContext $tenant,
        private readonly Passages $passages,
    ) {}

    /**
     * The draft and what to check in it, or why there is none (a key of assistant::ui.questions.draft_refused).
     *
     * @return array{answer: string, problems: list<string>}|array{refused: string}
     */
    public function handle(string $shopId, string $answerId, string $notes = ''): array
    {
        if (! Features::enabled('assistant.team_drafts', $shopId)) {
            return ['refused' => 'off'];
        }

        $input = $this->tenant->run($shopId, fn (): ?array => $this->input($shopId, $answerId, $notes));

        if ($input === null) {
            return ['refused' => 'gone'];
        }

        $fingerprint = 'assistant:team_draft:'.hash('sha256', self::PROMPT_VERSION.'|'.json_encode($input, JSON_UNESCAPED_UNICODE));

        if (is_array($kept = Cache::get($fingerprint))) {
            return $kept;
        }

        if ($this->usedToday($shopId) >= (int) Settings::get('assistant.team_drafts_per_day', $shopId)) {
            return ['refused' => 'limit'];
        }

        $result = ['refused' => 'failed'];

        $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            trigger: RunTrigger::Manual,
            input: ['question' => $input['question'], 'notes' => $input['notes'] !== ''],
            work: function (RunContext $run) use ($input, &$result): void {
                $writer = $this->role('answer');
                $checker = $this->role('post_check');

                if ($writer['provider'] === null || $checker['provider'] === null) {
                    $run->fail('assistant::runs.team_draft_no_provider');

                    return;
                }

                if ($writer['provider']->family() === $checker['provider']->family()) {
                    $run->fail('assistant::runs.team_draft_same_family', ['family' => $writer['provider']->label()]);

                    return;
                }

                try {
                    $data = $this->call($run, $writer, 'answer', (int) Settings::get('assistant.answer_max_output_tokens'), self::prompt('team_answer'), $input);
                    $answer = mb_substr(trim((string) ($data['answer'] ?? '')), 0, self::MAX_ANSWER);

                    if ($answer === '') {
                        $run->fail('assistant::runs.team_draft_empty');

                        return;
                    }

                    $verdict = $this->call($run, $checker, 'post_check', self::CHECK_OUTPUT_TOKENS, self::prompt('team_answer_check'), [
                        'question' => $input['question'], 'answer' => $answer, 'notes' => $input['notes'], 'page' => $input['page'], 'passages' => $input['passages'],
                    ]);
                } catch (SpendCapReached) {
                    $run->fail('assistant::runs.spend_cap');

                    return;
                } catch (ModelCallFailed $e) {
                    $run->fail('assistant::runs.model_failed', ['reason' => $e->reason], $e->getMessage());

                    return;
                }

                $problems = [];

                foreach ((array) ($verdict['problems'] ?? []) as $problem) {
                    if (is_string($problem) && trim($problem) !== '') {
                        $problems[] = mb_substr(trim($problem), 0, 300);
                    }
                }

                // Code's own check: a number the draft says that nothing it was given says.
                $known = json_encode([$input['question'], $input['notes'], $input['page'], $input['passages']], JSON_UNESCAPED_UNICODE);
                foreach (self::unknownNumbers($answer, (string) $known) as $number) {
                    $problems[] = (string) __('assistant::ui.questions.draft_number', ['number' => $number]);
                }

                $result = ['answer' => $answer, 'problems' => array_slice(array_values(array_unique($problems)), 0, self::MAX_PROBLEMS)];
                $run->output(['answer' => $answer, 'problems' => $result['problems']])
                    ->summary('assistant::runs.team_drafted', ['problems' => (string) count($result['problems'])]);
            },
        );

        if (isset($result['answer'])) {
            Cache::put($fingerprint, $result, now()->addDays(self::KEEP_DAYS));
        }

        return $result;
    }

    /**
     * The question, the page it was asked on, the store's text nearest it and the team's words.
     * Inside the shop's tenant.
     *
     * @return array{shop: string, question: string, notes: string, page: array<string, mixed>, passages: list<array{id: string, title: string, text: string}>}|null
     */
    private function input(string $shopId, string $answerId, string $notes): ?array
    {
        $row = AssistantAnswer::query()->with(['product', 'content'])->find($answerId);

        if ($row === null) {
            return null;
        }

        $max = (int) Settings::get('assistant.max_text_chars', $shopId);
        $page = $row->page();
        $subject = match (true) {
            $page instanceof CatalogProduct => Subject::product($page, $max),
            $page instanceof CatalogContent => Subject::article($page, $max),
            default => null,
        };
        $passages = [];

        foreach ($this->passages->near($shopId, (string) $row->question, ['content', 'product'], self::PASSAGES) as $i => $piece) {
            $passages[] = ['id' => 'p'.($i + 1), 'title' => (string) $piece['title'], 'text' => mb_substr(trim((string) $piece['text']), 0, self::MAX_PASSAGE)];
        }

        return [
            'shop' => (string) (Shop::query()->find($shopId)?->name ?? ''),
            'question' => (string) $row->question,
            'notes' => mb_substr(trim(preg_replace('/\s+/u', ' ', $notes) ?? ''), 0, self::MAX_NOTES),
            'page' => $subject?->context ?? [],
            'passages' => $passages,
        ];
    }

    private function usedToday(string $shopId): int
    {
        return Run::query()->forShop($shopId)->where('action', self::ACTION)->where('started_at', '>=', now()->startOfDay())->count();
    }

    /**
     * One model call, its spend checked first and accounted after.
     *
     * @param  array{provider: AiProviderName, model: string}  $role
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function call(RunContext $run, array $role, string $prices, int $maxOutput, string $system, array $input): array
    {
        $user = (string) json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $in = (float) Settings::get("assistant.{$prices}_input_usd_per_million");
        $out = (float) Settings::get("assistant.{$prices}_output_usd_per_million");

        $this->spend->assertCanSpend(((mb_strlen($system) + mb_strlen($user)) / 2 * $in + $maxOutput * $out) / 1_000_000);
        $reply = $this->chat->json($role['provider'], $role['model'], $system, $user, $maxOutput);
        $run->usage($role['provider']->value, $role['model'], $reply->inputTokens, $reply->outputTokens, 0, $reply->costUsd($in, $out));

        return $reply->data;
    }

    /** @return array{provider: AiProviderName|null, model: string} */
    private function role(string $name): array
    {
        return [
            'provider' => AiProviderName::tryFrom(strtolower(trim((string) Settings::get("assistant.{$name}_provider")))),
            'model' => trim((string) Settings::get("assistant.{$name}_model")),
        ];
    }

    /** @return list<string> the numbers in the draft that none of what it was given says */
    private static function unknownNumbers(string $answer, string $known): array
    {
        // What is left for the team to check is not a claim.
        $claims = preg_replace('/\[(?:לבדוק|check):[^\]]*\]/u', '', $answer) ?? $answer;
        preg_match_all('~\d+(?:[.,]\d+)?~u', $claims, $numbers);

        return array_values(array_unique(array_filter($numbers[0], fn (string $n): bool => ! str_contains($known, $n) && ! str_contains($known, str_replace(',', '.', $n)))));
    }

    private static function prompt(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/../Prompts/'.$name.'.v'.self::PROMPT_VERSION.'.md');
    }
}
