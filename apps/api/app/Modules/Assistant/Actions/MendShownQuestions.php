<?php

namespace App\Modules\Assistant\Actions;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\AgentModel;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\SpendCapReached;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Ai\Contracts\StructuredChatModel;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Runs\Models\Run;

/**
 * The questions other shoppers are shown ("What people ask here") as their askers meant to type them. A question
 * answered on its first ask keeps the scope check's wording (AnswerSiteQuestion); one kept before there was one, or
 * prepared or shared another way, never had it (Sela, 25.10: "אפשר להחיזר מוצר?" shown to everyone). Every shown
 * question not yet read is read here once, all of a shop's in one call of the small model, and its wording kept only
 * when it fixes the question rather than changes it (AnswerSiteQuestion::corrected). Never asked twice: a question
 * read is marked, whatever came of it.
 */
final class MendShownQuestions
{
    public const AGENT = 'assistant.question_mender';

    public const ACTION = 'assistant.mend_questions';

    /** At most this many questions in one call. */
    private const BATCH = 60;

    private const OUTPUT_TOKENS = 4000;

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
        private readonly ChatModel $models,
        private readonly SpendGuard $spend,
    ) {}

    public function handle(string $shopId, RunTrigger $trigger = RunTrigger::System): ?Run
    {
        $due = $this->tenant->run($shopId, fn () => AnswerSiteQuestion::siteAnswers()->whereNotNull('shared_at')
            ->where('outcome', AssistantAnswer::ANSWERED)->orderBy('id')->get()
            ->filter(fn (AssistantAnswer $a): bool => ! (bool) (((array) $a->flags)['mend_read'] ?? false))
            ->take(self::BATCH)->values());

        if ($due->isEmpty()) {
            return null;
        }

        return $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            trigger: $trigger,
            input: ['questions' => $due->count()],
            work: fn (RunContext $run) => $this->tenant->run($shopId, fn () => $this->mend($run, $due->all())),
        );
    }

    /** @param list<AssistantAnswer> $answers */
    private function mend(RunContext $run, array $answers): void
    {
        $role = AgentModel::from('assistant.scope_provider', 'assistant.scope_model');
        $input = (string) json_encode(['questions' => array_map(fn (AssistantAnswer $a, int $i): array => ['id' => 'q'.($i + 1), 'text' => (string) $a->question], $answers, array_keys($answers))], JSON_UNESCAPED_UNICODE);
        $in = (float) Settings::get('assistant.scope_input_usd_per_million');
        $out = (float) Settings::get('assistant.scope_output_usd_per_million');
        $file = AnswerSiteQuestion::promptFile('mend_questions');
        $schema = AnswerSiteQuestion::schemaFor($file);

        try {
            $this->spend->assertCanSpend(estimatedUsd: ((int) ceil(mb_strlen($input) / 2) * $in + self::OUTPUT_TOKENS * $out) / 1_000_000);
            $reply = $schema !== null && $this->models instanceof StructuredChatModel
                ? $this->models->structured($role->provider, $role->model, (string) file_get_contents((string) $file), $input, $schema, self::OUTPUT_TOKENS)
                : $this->models->json($role->provider, $role->model, (string) file_get_contents((string) $file), $input, self::OUTPUT_TOKENS);
        } catch (SpendCapReached $e) {
            $run->fail('assistant::runs.spend_cap', [], $e->getMessage());

            return;
        } catch (ModelCallFailed $e) {
            $run->fail('assistant::runs.model_failed', ['reason' => $e->reason], $e->getMessage());

            return;
        }

        $run->usage($role->provider->value, $role->model, $reply->inputTokens, $reply->outputTokens, 0, $reply->costUsd($in, $out));
        $wordings = array_column(array_filter((array) ($reply->data['questions'] ?? []), 'is_array'), 'corrected', 'id');
        $mended = 0;

        foreach ($answers as $i => $answer) {
            $corrected = AnswerSiteQuestion::corrected((string) $answer->question, $wordings['q'.($i + 1)] ?? null);
            $mended += $corrected === null ? 0 : 1;
            $answer->forceFill(['flags' => ['mend_read' => true] + ($corrected === null ? [] : ['corrected' => $corrected]) + (array) $answer->flags])->save();
        }

        $run->output(['questions' => count($answers), 'mended' => $mended])->summary('assistant::runs.questions_mended', ['count' => $mended, 'of' => count($answers)]);
    }
}
