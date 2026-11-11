<?php

namespace App\Modules\Assistant\Actions;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\SpendCapReached;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Ai\Contracts\StructuredChatModel;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Assistant\Filament\Merchant\Pages\AssistantSettings;
use App\Modules\Assistant\Jobs\CheckAnswerChangeJob;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Assistant\Models\AssistantAnswerRule;
use App\Modules\Assistant\Models\AssistantSearchAsk;
use App\Modules\Assistant\Support\AnswerRules;
use App\Modules\Assistant\Support\Question;
use App\Modules\Retrieval\Contracts\Passages;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Runs\Models\Run;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * A change the team wants in how the store answers (a rule of its own, or its tone), checked before it is kept, so it
 * cannot break the answers shoppers get:
 *
 * 1. Code: a rule that is empty, too long, a link, someone's details, an attempt to change how the assistant works, the
 *    same as another, or one too many is refused at once (AnswerRules); a tone outside the four, or a note with a link.
 * 2. A model of the other family than the writer's reads the change against the store's other rules, its tone and the
 *    pages nearest to it: unsafe, a fact (it belongs in the store's information), against the site, against another
 *    rule, or unclear is refused, with why and how to write it instead.
 * 3. The question the team is on, and the store's most asked answered questions of the last weeks, are answered again
 *    as they would be with the change (the writer's prompt that reads the rules, answer_site v23), nothing saved. A
 *    question that had an answer and has none with the change stops it.
 *
 * Only a change that passed all three is kept (apply()), and the team is shown the answer as it comes out with it.
 * The check is kept for a day by its id; the same change is checked again only when asked again. Inside a day's
 * limit (assistant.answer_changes_per_day): each check answers several questions, every call accounted in its run.
 */
final class CheckAnswerChange
{
    public const AGENT = 'assistant.change_checker';

    public const ACTION = 'assistant.check_answer_change';

    public const PROMPT_VERSION = 1;

    public const RULE = 'rule';

    public const TONE = 'tone';

    /** How a check stands: being checked, done (passed or not), kept, or failed (nothing could be checked now). */
    public const CHECKING = 'checking';

    public const READY = 'ready';

    public const APPLIED = 'applied';

    public const FAILED = 'failed';

    private const KEEP_HOURS = 24;

    private const OUTPUT_TOKENS = 500;

    private const PASSAGES = 6;

    private const MAX_PASSAGE = 600;

    private const MAX_WORDS = 400;

    /** The questions the store's own most asked are taken from: the last weeks. */
    private const RECENT_DAYS = 30;

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly ChatModel $chat,
        private readonly SpendGuard $spend,
        private readonly TenantContext $tenant,
        private readonly Passages $passages,
        private readonly AnswerSiteQuestion $answerer,
    ) {}

    /**
     * Starts a check: code's at once, the rest on the queue. The check's id, or why code refused (a key of
     * assistant::ui.inbox.why.refused).
     *
     * @param  array{kind: string, text?: string, replaces?: string|null, style?: string, note?: string}  $change
     * @return array{id: string}|array{refused: string}
     */
    public function start(string $shopId, array $change, ?string $question, ?int $userId = null): array
    {
        $change = $this->tenant->run($shopId, fn (): ?array => $this->normalized($shopId, $change));

        if ($change === null) {
            return ['refused' => 'gone'];
        }

        if (isset($change['refused'])) {
            return ['refused' => (string) $change['refused']];
        }

        if ($this->usedToday($shopId) >= (int) Settings::get('assistant.answer_changes_per_day', $shopId)) {
            return ['refused' => 'limit'];
        }

        $id = (string) Str::ulid();
        Cache::put(self::key($id), [
            'id' => $id, 'shop' => $shopId, 'status' => self::CHECKING, 'change' => $change,
            'question' => $question === null ? null : mb_substr(trim($question), 0, 500), 'by' => $userId, 'at' => now()->toIso8601String(),
        ], now()->addHours(self::KEEP_HOURS));
        CheckAnswerChangeJob::dispatch($id);

        return ['id' => $id];
    }

    /**
     * A check as it stands, for the shop that started it only.
     *
     * @return array<string, mixed>|null
     */
    public function state(string $id, string $shopId): ?array
    {
        $state = Cache::get(self::key($id));

        return is_array($state) && ($state['shop'] ?? null) === $shopId ? $state : null;
    }

    /** The check itself, on the queue (CheckAnswerChangeJob). */
    public function run(string $id): void
    {
        $state = Cache::get(self::key($id));

        if (! is_array($state) || ($state['status'] ?? null) !== self::CHECKING) {
            return;
        }

        $shopId = (string) $state['shop'];
        $state = $this->tenant->run($shopId, fn (): array => $this->checked($shopId, $state));
        Cache::put(self::key($id), $state, now()->addHours(self::KEEP_HOURS));
    }

    /**
     * Keeps a change that passed its check, as it was checked: the rule added (or in place of the one it replaces), or
     * the tone. False for anything else (not checked, not passed, another shop's, kept already).
     */
    public function apply(string $id, string $shopId, ?int $userId = null): bool
    {
        $state = $this->state($id, $shopId);

        if ($state === null || ($state['status'] ?? null) !== self::READY || ! ($state['passed'] ?? false)) {
            return false;
        }

        $change = (array) $state['change'];

        $this->tenant->run($shopId, function () use ($shopId, $change, $userId): void {
            if ($change['kind'] === self::TONE) {
                Settings::set('assistant.answer_tone', (string) $change['style'], $shopId);
                Settings::set('assistant.answer_tone_note', (string) $change['note'], $shopId);

                return;
            }

            if (($change['replaces'] ?? null) !== null) {
                AssistantAnswerRule::query()->whereKey($change['replaces'])->delete();
            }

            AssistantAnswerRule::query()->create(['shop_id' => $shopId, 'text' => (string) $change['text'], 'created_by' => $userId]);
        });

        $state['status'] = self::APPLIED;
        Cache::put(self::key($id), $state, now()->addHours(self::KEEP_HOURS));

        return true;
    }

    /** A check that stopped on the way: could not be checked now (its job failed). */
    public function failed(string $id): void
    {
        $state = Cache::get(self::key($id));

        if (is_array($state) && ($state['status'] ?? null) === self::CHECKING) {
            Cache::put(self::key($id), ['status' => self::FAILED, 'why' => 'later'] + $state, now()->addHours(self::KEEP_HOURS));
        }
    }

    /** A rule taken away: nothing a shopper is told can be made wrong by fewer rules, so it is not checked. */
    public function remove(string $shopId, string $ruleId): void
    {
        $this->tenant->run($shopId, fn () => AssistantAnswerRule::query()->whereKey($ruleId)->delete());
    }

    /**
     * The change as it is checked and kept: a rule in one paragraph, a tone of the four with its note; or why code
     * refuses it. Null when the rule it replaces is gone. Inside the shop's tenant.
     *
     * @param  array<string, mixed>  $change
     * @return array<string, mixed>|null
     */
    private function normalized(string $shopId, array $change): ?array
    {
        if (($change['kind'] ?? null) === self::TONE) {
            $style = (string) ($change['style'] ?? '');
            $note = AnswerRules::clean((string) ($change['note'] ?? ''));

            if (! in_array($style, AssistantSettings::TONES, true)) {
                return ['refused' => AnswerRules::EMPTY];
            }

            if (mb_strlen($note) > AssistantSettings::TONE_NOTE_CHARS) {
                return ['refused' => AnswerRules::LONG];
            }

            if ($note !== '' && ($why = AnswerRules::refused($shopId, $note, [])) !== null && $why !== AnswerRules::MANY && $why !== AnswerRules::EMPTY) {
                return ['refused' => $why];
            }

            return ['kind' => self::TONE, 'style' => $style, 'note' => $note];
        }

        $replaces = isset($change['replaces']) && $change['replaces'] !== '' ? (string) $change['replaces'] : null;

        if ($replaces !== null && ! AssistantAnswerRule::query()->whereKey($replaces)->exists()) {
            return null;
        }

        $others = AssistantAnswerRule::query()->when($replaces !== null, fn ($q) => $q->whereKeyNot($replaces))
            ->orderBy('created_at')->orderBy('id')->pluck('text')->map(fn ($t): string => (string) $t)->all();
        $text = AnswerRules::clean((string) ($change['text'] ?? ''));

        if (($why = AnswerRules::refused($shopId, $text, $others)) !== null) {
            return ['refused' => $why];
        }

        return ['kind' => self::RULE, 'text' => $text, 'replaces' => $replaces];
    }

    /**
     * The model's reading and the questions answered again; the check as it ends. Inside the shop's tenant.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function checked(string $shopId, array $state): array
    {
        $change = (array) $state['change'];
        $rules = AnswerRules::texts($shopId);
        $replaced = $change['kind'] === self::RULE && $change['replaces'] !== null
            ? (string) AssistantAnswerRule::query()->whereKey($change['replaces'])->value('text') : null;
        $others = array_values(array_filter($rules, fn (string $r): bool => $r !== $replaced));
        $tone = AnswerSiteQuestion::tone($shopId);

        // what the answers would be written with, were the change kept
        $after = $change['kind'] === self::TONE
            ? ['tone' => $change['style'] === AnswerSiteQuestion::TONE_DEFAULT && $change['note'] === '' ? null : ['style' => $change['style']] + ($change['note'] === '' ? [] : ['note' => $change['note']]), 'rules' => $rules]
            : ['tone' => $tone, 'rules' => [...$others, (string) $change['text']]];

        $reading = $this->read($shopId, $change, $others, $tone, $replaced);

        if ($reading === null) {
            return ['status' => self::FAILED, 'why' => 'later'] + $state;
        }

        if ($reading['verdict'] !== 'ok') {
            return ['status' => self::READY, 'passed' => false, 'verdict' => $reading['verdict'], 'why' => $reading['why'],
                'suggestion' => $reading['suggestion'], 'conflicts' => $reading['conflicts'], 'items' => []] + $state;
        }

        $items = [];
        $version = $after['rules'] === [] ? null : AnswerSiteQuestion::RULES_FROM;
        // the language shoppers are answered in
        $locale = (string) (Shop::query()->whereKey($shopId)->value('content_locale') ?: 'he');

        foreach ($this->questions($shopId, $state['question'] ?? null) as $asked) {
            try {
                $out = $this->answerer->withVoice($after['tone'], $after['rules'],
                    fn (): array => $this->answerer->replay($shopId, $asked['question'], $asked['products'], $version, $locale));
            } catch (\Throwable $e) {
                report($e);

                return ['status' => self::FAILED, 'why' => 'later'] + $state;
            }

            $outcome = (string) ($out['result']['outcome'] ?? '');

            // nothing could be asked now (the month's allowance, a provider down): not a verdict on the change
            if (in_array($outcome, ['unavailable', 'limit'], true)) {
                return ['status' => self::FAILED, 'why' => 'later'] + $state;
            }

            $answered = $outcome === AssistantAnswer::ANSWERED;
            $items[] = [
                'question' => $asked['question'],
                'mine' => $asked['mine'],
                'before' => $asked['answer'],
                'after' => $answered ? trim((string) ($out['result']['answer'] ?? '')) : '',
                // what had an answer keeps one; what had none is no worse for the change
                'ok' => $answered || ! $asked['answered'],
            ];
        }

        // a store not asked anything yet has no answer to break: the reading alone decides
        return ['status' => self::READY, 'passed' => ! in_array(false, array_column($items, 'ok'), true), 'verdict' => 'ok',
            'why' => '', 'suggestion' => '', 'conflicts' => [], 'items' => $items, 'none' => $items === []] + $state;
    }

    /**
     * The model's reading of the change, by a family other than the writer's; null when it could not be asked.
     *
     * @param  array<string, mixed>  $change
     * @param  list<string>  $others
     * @param  array<string, mixed>|null  $tone
     * @return array{verdict: string, why: string, suggestion: string, conflicts: list<array{title: string}>}|null
     */
    private function read(string $shopId, array $change, array $others, ?array $tone, ?string $replaced): ?array
    {
        $words = $change['kind'] === self::TONE ? trim($change['style'].' '.$change['note']) : (string) $change['text'];
        $passages = [];
        $titles = [];

        foreach ($change['kind'] === self::TONE && $change['note'] === '' ? [] : $this->passages->near($shopId, $words, ['content'], self::PASSAGES) as $i => $piece) {
            $id = 'p'.($i + 1);
            $passages[] = ['id' => $id, 'title' => (string) $piece['title'], 'text' => mb_substr(trim((string) $piece['text']), 0, self::MAX_PASSAGE)];
            $titles[$id] = (string) $piece['title'];
        }

        $input = [
            'change' => $change['kind'] === self::TONE ? ['kind' => self::TONE, 'style' => $change['style'], 'note' => $change['note']]
                : ['kind' => self::RULE, 'text' => $change['text']] + ($replaced === null ? [] : ['replaces' => $replaced]),
            'rules' => $others,
            'tone' => $tone,
            'passages' => $passages,
            'locale' => app()->getLocale() === 'en' ? 'en' : 'he',
        ];
        $fingerprint = 'assistant:change_check:'.hash('sha256', self::PROMPT_VERSION.'|'.json_encode($input, JSON_UNESCAPED_UNICODE));

        if (is_array($kept = Cache::get($fingerprint))) {
            return $kept;
        }

        $result = null;

        $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            trigger: RunTrigger::Manual,
            input: ['kind' => $change['kind'], 'rules' => count($others), 'passages' => count($passages)],
            work: function (RunContext $run) use ($input, $titles, &$result): void {
                $checker = $this->role('post_check');
                $writer = $this->role('answer');

                if ($checker['provider'] === null || $writer['provider'] === null) {
                    $run->fail('assistant::runs.team_draft_no_provider');

                    return;
                }

                // what one family writes, the other checks: a change to the writer's rules is never read by its own family
                if ($checker['provider']->family() === $writer['provider']->family()) {
                    $run->fail('assistant::runs.team_draft_same_family', ['family' => $writer['provider']->label()]);

                    return;
                }

                $file = __DIR__.'/../Prompts/answer_change_check.v'.self::PROMPT_VERSION.'.md';
                $system = (string) file_get_contents($file);
                $user = (string) json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $in = (float) Settings::get('assistant.post_check_input_usd_per_million');
                $out = (float) Settings::get('assistant.post_check_output_usd_per_million');
                $schema = AnswerSiteQuestion::schemaFor($file);

                try {
                    $this->spend->assertCanSpend(((mb_strlen($system) + mb_strlen($user)) / 2 * $in + self::OUTPUT_TOKENS * $out) / 1_000_000);
                    $reply = $schema !== null && $this->chat instanceof StructuredChatModel
                        ? $this->chat->structured($checker['provider'], $checker['model'], $system, $user, $schema, self::OUTPUT_TOKENS)
                        : $this->chat->json($checker['provider'], $checker['model'], $system, $user, self::OUTPUT_TOKENS);
                } catch (SpendCapReached) {
                    $run->fail('assistant::runs.spend_cap');

                    return;
                } catch (ModelCallFailed $e) {
                    $run->fail('assistant::runs.model_failed', ['reason' => $e->reason], $e->getMessage());

                    return;
                }

                $run->usage($checker['provider']->value, $checker['model'], $reply->inputTokens, $reply->outputTokens, 0, $reply->costUsd($in, $out));
                $verdict = (string) ($reply->data['verdict'] ?? '');

                // anything but a reading code knows is no "ok"
                if (! in_array($verdict, ['ok', 'unsafe', 'fact', 'contradicts_site', 'conflict', 'unclear'], true)) {
                    $run->fail('assistant::runs.change_unread');

                    return;
                }

                // only passages that were sent, once, with their titles from code
                $conflicts = [];
                foreach ((array) ($reply->data['conflicts'] ?? []) as $ref) {
                    if (is_string($ref) && isset($titles[$ref]) && ! isset($conflicts[$ref])) {
                        $conflicts[$ref] = ['title' => $titles[$ref]];
                    }
                }

                $result = [
                    'verdict' => $verdict,
                    'why' => $verdict === 'ok' ? '' : mb_substr(trim((string) ($reply->data['why'] ?? '')), 0, self::MAX_WORDS),
                    'suggestion' => $verdict === 'ok' ? '' : mb_substr(trim((string) ($reply->data['suggestion'] ?? '')), 0, self::MAX_WORDS),
                    'conflicts' => array_values($conflicts),
                ];
                $run->output($result)->summary('assistant::runs.change_checked', ['verdict' => (string) __('assistant::ui.inbox.why.verdicts.'.$verdict)]);
            },
        );

        if ($result !== null) {
            Cache::put($fingerprint, $result, now()->addDays(7));
        }

        return $result;
    }

    /**
     * The questions a change is tried on: the one the team is on first, then the store's most asked answered questions
     * of the last weeks (assistant.answer_change_questions), each once, with the products that were on screen when it
     * was last asked and the answer it got. Inside the shop's tenant.
     *
     * @return list<array{question: string, products: list<string>, answer: string, answered: bool, mine: bool}>
     */
    private function questions(string $shopId, ?string $mine): array
    {
        $out = [];
        $seen = [];
        $add = function (AssistantSearchAsk $ask, bool $isMine) use (&$out, &$seen): void {
            $key = Question::key((string) $ask->question);
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $answer = trim((string) ($ask->getRelation('answer')?->getAttribute('answer') ?? $ask->getAttributes()['answer'] ?? ''));
            $out[] = [
                'question' => (string) $ask->question,
                'products' => array_values(array_map('strval', (array) $ask->products)),
                'answer' => $answer,
                'answered' => $ask->outcome === AssistantAnswer::ANSWERED && $answer !== '',
                'mine' => $isMine,
            ];
        };

        if ($mine !== null && trim($mine) !== '') {
            $latest = AssistantSearchAsk::query()->with('answer:id,answer')->where('created_at', '>=', now()->subDays(self::RECENT_DAYS * 3))
                ->latest('created_at')->orderByDesc('id')->limit(400)->get()
                ->first(fn (AssistantSearchAsk $a): bool => Question::key((string) $a->question) === Question::key($mine));
            if ($latest !== null) {
                $add($latest, true);
            } else {
                $out[] = ['question' => trim($mine), 'products' => [], 'answer' => '', 'answered' => false, 'mine' => true];
                $seen[Question::key($mine)] = true;
            }
        }

        $more = (int) Settings::get('assistant.answer_change_questions', $shopId);
        if ($more > 0) {
            $asks = AssistantSearchAsk::query()->with('answer:id,answer')->where('created_at', '>=', now()->subDays(self::RECENT_DAYS))
                ->where('outcome', AssistantAnswer::ANSWERED)->latest('created_at')->orderByDesc('id')->limit(1000)->get();
            // the most asked first; among those asked as often, the latest (sorted explicitly, the same on every database)
            $groups = $asks->groupBy(fn (AssistantSearchAsk $a): string => Question::key((string) $a->question))
                ->sort(fn ($a, $b): int => [$b->count(), $b->first()->created_at?->getTimestamp() ?? 0] <=> [$a->count(), $a->first()->created_at?->getTimestamp() ?? 0]);
            $taken = 0;
            foreach ($groups as $group) {
                if ($taken >= $more) {
                    break;
                }
                $before = count($out);
                $add($group->first(), false);
                $taken += count($out) - $before;
            }
        }

        return $out;
    }

    private function usedToday(string $shopId): int
    {
        return Run::query()->forShop($shopId)->where('action', self::ACTION)->where('started_at', '>=', now()->startOfDay())->count();
    }

    /** @return array{provider: AiProviderName|null, model: string} */
    private function role(string $name): array
    {
        return [
            'provider' => AiProviderName::tryFrom(strtolower(trim((string) Settings::get("assistant.{$name}_provider")))),
            'model' => trim((string) Settings::get("assistant.{$name}_model")),
        ];
    }

    private static function key(string $id): string
    {
        return 'assistant:answer_change:'.$id;
    }
}
