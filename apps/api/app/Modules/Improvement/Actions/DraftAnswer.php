<?php

namespace App\Modules\Improvement\Actions;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\SpendCapReached;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Improvement\Models\ImprovementProposal;
use App\Modules\Retrieval\Contracts\Passages;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Tenancy\Models\Shop;

/**
 * The answer to a question shoppers keep asking, written from the store's own text: the team
 * presses a button, code finds the pieces of the store's pages, guides and products nearest the
 * question, a model writes the answer from them only, and a model of the other family checks it.
 * What the store's text does not say stays "[לבדוק: ...]" for the team to fill in. The answer
 * waits in the box on the suggestions screen; nothing reaches shoppers before the team approves.
 */
final class DraftAnswer
{
    public const AGENT = 'improvement.answer_writer';

    public const ACTION = 'improvement.draft_answer';

    private const PROMPT_VERSION = 'v1';

    private const PROMPTS = __DIR__.'/../Prompts/';

    /** Pieces of the store's text the writer gets. */
    private const PASSAGES = 8;

    private const MAX_PASSAGE = 1200;

    private const MAX_ANSWER = 1200;

    private const MAX_PROBLEMS = 6;

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly ChatModel $chat,
        private readonly SpendGuard $spend,
        private readonly TenantContext $tenant,
        private readonly Passages $passages,
    ) {}

    /** The proposal with its new answer, or null when no model could write one; the run says why. */
    public function handle(ImprovementProposal $proposal, RunTrigger $trigger = RunTrigger::Manual): ?ImprovementProposal
    {
        $written = null;

        $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $proposal->shop_id,
            trigger: $trigger,
            work: function (RunContext $run) use ($proposal, &$written): void {
                $writer = $this->role('answer_writer');
                $checker = $this->role('answer_checker');

                if ($writer['provider'] === null || $checker['provider'] === null) {
                    $run->fail('improvement::runs.answer_no_provider');

                    return;
                }

                if ($writer['provider']->family() === $checker['provider']->family()) {
                    $run->fail('improvement::runs.same_family', ['family' => $writer['provider']->label()]);

                    return;
                }

                $input = $this->tenant->run($proposal->shop_id, fn (): array => $this->input($proposal));

                try {
                    $answer = $this->write($run, $writer, $input);

                    if ($answer === null) {
                        $run->fail('improvement::runs.answer_empty');

                        return;
                    }

                    $verdict = $this->check($run, $checker, $input, $answer['answer']);
                } catch (SpendCapReached) {
                    $run->fail('improvement::runs.answer_spend_cap');

                    return;
                } catch (ModelCallFailed $e) {
                    $run->fail('improvement::runs.answer_failed', ['reason' => $e->reason]);

                    return;
                }

                $titles = array_column($input['passages'], 'title', 'id');
                $written = $this->tenant->run($proposal->shop_id, function () use ($proposal, $answer, $verdict, $writer, $checker, $titles, $run): ImprovementProposal {
                    $proposal->detail = array_replace($proposal->detail, [
                        'answer' => $answer['answer'],
                        'ai' => [
                            'by' => $writer['name'].' '.$writer['model'],
                            'checked_by' => $checker['name'].' '.$checker['model'],
                            'problems' => $verdict['problems'],
                            'sources' => array_values(array_unique(array_map(fn (string $id): string => (string) $titles[$id], $answer['sources']))),
                            'run_id' => $run->runId,
                        ],
                    ]);
                    $proposal->save();

                    return $proposal;
                });

                $run->output(['question' => $input['question'], 'sources' => count($answer['sources']), 'problems' => $verdict['problems']])
                    ->summary('improvement::runs.answer_drafted', ['question' => $input['question'], 'problems' => (string) count($verdict['problems'])]);
            },
        );

        return $written;
    }

    /**
     * What the writer gets: the question, how shoppers asked it, and the store's own text nearest
     * to it, with ids. Inside the shop's tenant.
     *
     * @return array<string, mixed>
     */
    private function input(ImprovementProposal $proposal): array
    {
        $question = trim((string) ($proposal->detail['question'] ?? $proposal->title));
        $found = $this->passages->near($proposal->shop_id, $question, ['content', 'product'], self::PASSAGES);
        $passages = [];

        foreach ($found as $i => $piece) {
            $passages[] = ['id' => 'p'.($i + 1), 'title' => (string) $piece['title'], 'text' => mb_substr(trim((string) $piece['text']), 0, self::MAX_PASSAGE)];
        }

        return [
            'shop' => (string) (Shop::query()->find($proposal->shop_id)?->name ?? ''),
            'question' => $question,
            'asked' => array_values(array_filter(array_map(
                fn (array $e): string => trim((string) ($e['question'] ?? $e['query'] ?? '')).' ×'.(int) ($e['asked'] ?? $e['searches'] ?? 0),
                $proposal->evidence,
            ), fn (string $s): bool => ! str_starts_with($s, ' ×'))),
            'passages' => $passages,
            'draft' => (string) ($proposal->detail['answer'] ?? ''),
        ];
    }

    /**
     * @param  array{provider: AiProviderName, name: string, model: string}  $writer
     * @param  array<string, mixed>  $input
     * @return array{answer: string, sources: list<string>}|null
     */
    private function write(RunContext $run, array $writer, array $input): ?array
    {
        $data = $this->call($run, $writer, 'answer_writer', $this->prompt('answer'), $input);
        $answer = mb_substr(trim((string) ($data['answer'] ?? '')), 0, self::MAX_ANSWER);
        $allowed = array_column($input['passages'], 'id');
        $sources = array_values(array_unique(array_filter(array_map('strval', (array) ($data['sources'] ?? [])), fn (string $id): bool => in_array($id, $allowed, true))));

        return $answer === '' ? null : ['answer' => $answer, 'sources' => $sources];
    }

    /**
     * @param  array{provider: AiProviderName, name: string, model: string}  $checker
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, problems: list<string>}
     */
    private function check(RunContext $run, array $checker, array $input, string $answer): array
    {
        $data = $this->call($run, $checker, 'answer_checker', $this->prompt('answer_check'), ['question' => $input['question'], 'answer' => $answer, 'passages' => $input['passages']]);
        $problems = [];

        foreach ((array) ($data['problems'] ?? []) as $problem) {
            if (is_string($problem) && trim($problem) !== '') {
                $problems[] = mb_substr(trim($problem), 0, 300);
            }
        }

        $problems = array_slice($problems, 0, self::MAX_PROBLEMS);

        return ['ok' => ($data['ok'] ?? false) === true && $problems === [], 'problems' => $problems];
    }

    /**
     * One model call, its spend checked first and accounted after.
     *
     * @param  array{provider: AiProviderName, name: string, model: string}  $role
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function call(RunContext $run, array $role, string $settings, string $system, array $input): array
    {
        $user = (string) json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $in = (float) Settings::get("improvement.{$settings}_input_usd_per_million");
        $out = (float) Settings::get("improvement.{$settings}_output_usd_per_million");
        $maxOutput = (int) Settings::get("improvement.{$settings}_max_output_tokens");

        $this->spend->assertCanSpend(((mb_strlen($system) + mb_strlen($user)) / 2 * $in + $maxOutput * $out) / 1_000_000);
        $reply = $this->chat->json($role['provider'], $role['model'], $system, $user, $maxOutput);
        $run->usage($role['name'], $role['model'], $reply->inputTokens, $reply->outputTokens, 0, $reply->costUsd($in, $out));

        return $reply->data;
    }

    /** @return array{provider: AiProviderName|null, name: string, model: string} */
    private function role(string $name): array
    {
        $provider = strtolower(trim((string) Settings::get("improvement.{$name}_provider")));

        return ['provider' => AiProviderName::tryFrom($provider), 'name' => $provider, 'model' => trim((string) Settings::get("improvement.{$name}_model"))];
    }

    private function prompt(string $name): string
    {
        return (string) file_get_contents(self::PROMPTS.$name.'.'.self::PROMPT_VERSION.'.md');
    }
}
