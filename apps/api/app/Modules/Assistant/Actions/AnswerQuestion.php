<?php

namespace App\Modules\Assistant\Actions;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\AgentModel;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Contracts\SpendCapReached;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Assistant\Support\Barrier;
use App\Modules\Assistant\Support\DailyQuestions;
use App\Modules\Assistant\Support\Question;
use App\Modules\Assistant\Support\Subject;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Enums\RunTrigger;

/**
 * Answers a shopper's question about one page: a product, or a guide the store published. The
 * Subject says which, carries what the writer may answer from, and picks the prompts for it.
 *
 * 0. The barrier (see Barrier): a blocked visitor, instructions to the assistant, markup, a flood
 *    or personal details are refused in code; a shopper who may hurt themselves gets care.
 * 1. The same question asked before about this page: the saved answer, no model, once it is
 *    shared (assistant.share_after_visitors different visitors got it clean, or the team's), or
 *    to a visitor who already got it. An answer saved by an older prompt version is asked again.
 * 2. Too short, over the visitor's or the shop's daily questions: refused in code.
 * 3. A small model decides whether the question is about the page. When it is not, a fixed
 *    refusal, and the writing model never sees the question.
 * 4. The writing model answers from the store's own information first — the product's checked
 *    facts, or the guide's text — and from general knowledge when that does not say.
 * 5. Code refuses an answer with contact details, a price, or a number the store's information
 *    does not have. The small model then checks the answer is about this page, consistent with
 *    the store's information and on topic. Only then the shopper sees it.
 *
 * Every call asks SpendGuard first and records tokens and cost on one run. Every answer is saved.
 */
final class AnswerQuestion
{
    public const AGENT = 'assistant.answerer';

    public const ACTION = 'assistant.answer';

    public const PROMPT_VERSION = 5;

    /** How much of the lines the store's team wrote (the knowledge centre's own) the writer reads. */
    private const STORE_CHARS = 2500;

    private const MIN_CHARS = 3;

    private const CHECK_OUTPUT_TOKENS = 400;

    private const MAX_ANSWER_CHARS = 1200;

    /**
     * What makes a question on a product's page the store's, not the product's: delivery, returns and
     * cancelling, warranty, payments, the shop itself, and its services (resizing a ring). Its card does
     * not say these and the writer may not promise them, so they came back "not found" though the
     * store's pages say them. Narrower than the site's own "store" kind: "באילו אורכים זה מגיע" is the
     * product (it comes in lengths), "מתי זה מגיע" the store (it arrives); cleaning or care is the product.
     * Hebrew final letters are read as regular ones.
     */
    private const STORE_WORDS = [
        'משלוח', 'שליח', 'איסופ', 'זמנ אספקה', 'תוכ כמה זמנ', 'כמה זמנ (?:זה )?(?:לוקח|עד)', 'מתי (?:זה |הוא |היא )?(?:מגיע|תגיע|יגיע|אקבל|יישלח|נשלח)',
        'החז(?:י)?ר', 'החלפ', 'ביטול', 'לבטל', 'אחריות', 'תשלומ', 'סניפ', 'שעות (?:פתיחה|פעילות)', 'חנות פיזית', 'קופונ', 'אריזת מתנה',
        'להגד(?:י)?ל', 'להקט(?:י)?נ', 'הגדלה', 'הגדלת', 'הקטנת', 'התאמת מידה', 'שינוי מידה',
        'shipping', 'deliver', 'arriv', 'return', 'refund', 'exchange', 'warranty', 'payment', 'installment', 'resiz',
    ];

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
        private readonly ChatModel $models,
        private readonly SpendGuard $spend,
    ) {}

    /**
     * @return array{outcome: string, answer: string, from: string, source?: string} outcome: answered, no_info,
     *                                                                               out_of_scope, invalid, limit or unavailable; from: bank, model or none
     */
    public function handle(string $shopId, string $pageType, string $externalId, string $question, string $visitorHash, string $locale = 'he'): array
    {
        $question = Question::clean($question, (int) Settings::get('assistant.max_question_chars', $shopId));
        $onArticle = $pageType === 'content';

        if (! Features::enabled($onArticle ? 'assistant.on_content' : 'assistant.on_products', $shopId)
            || mb_strlen(Question::normalize($question)) < self::MIN_CHARS) {
            return $this->fixed('invalid', $locale);
        }

        return $this->tenant->run($shopId, function () use ($shopId, $onArticle, $externalId, $question, $visitorHash, $locale): array {
            // A question about the store asked on a product's page ("תוך כמה זמן זה מגיע?", "אפשר להחזיר?")
            // goes to the site's answerer, which reads the store's pages; its barrier, saved answers and
            // limits apply there. One this product already has an answer to (the team's) stays here.
            if (! $onArticle && self::aboutTheStore($question) && Features::enabled('assistant.on_search', $shopId) && ! $this->answeredHere($externalId, $question)) {
                return $this->bySite($shopId, $question, $visitorHash, $locale);
            }

            if (($stop = $this->guarded($shopId, $question, $visitorHash, $locale)) !== null) {
                return $stop;
            }

            $maxTextChars = (int) Settings::get('assistant.max_text_chars', $shopId);
            $subject = $onArticle
                ? ($article = CatalogContent::query()->active()->where('external_id', $externalId)->first()) === null ? null : Subject::article($article, $maxTextChars)
                : (($product = CatalogProduct::query()->whereNull('removed_at')->where('external_id', $externalId)->first()) === null ? null : Subject::product($product, $maxTextChars));

            if ($subject === null) {
                return $this->fixed('invalid', $locale);
            }

            $saved = AssistantAnswer::query()->where($subject->column(), $subject->id)->where('question_key', Question::key($question))->first();

            // An answer from an older prompt is asked again: the rules for what shoppers see changed.
            // The team's own answers stay.
            // And one written before the team last changed its own lines: they may answer it now ("all our diamonds are natural").
            if ($saved !== null && $saved->source !== 'team' && ($saved->prompt_version < self::PROMPT_VERSION || $this->storeLinesChangedSince($saved))) {
                $saved->delete();
                $saved = null;
            }

            $answered = $saved !== null && $saved->status === AssistantAnswer::SHOWN && $saved->outcome === AssistantAnswer::ANSWERED;

            // A written answer is everyone's once it is shared (enough different visitors got it
            // clean, or the team wrote or approved it); before that, only a visitor who already got
            // it gets it again, and asking again changes nothing.
            if ($answered && ($saved->isShared() || $saved->askedBy($visitorHash))) {
                $saved->countVisitor($visitorHash, (int) Settings::get('assistant.share_after_visitors', $shopId));

                return array_filter(['outcome' => $saved->outcome, 'answer' => (string) $saved->answer, 'from' => 'bank', 'source' => $saved->source]);
            }

            // "No information" and "not about this page" are the same for everyone and show nothing written.
            if ($saved !== null && ! $answered) {
                $saved->forceFill(['last_asked_at' => now()])->save();

                return $this->fixed($saved->outcome === AssistantAnswer::OUT_OF_SCOPE ? 'out_of_scope' : 'no_info', $locale, 'bank');
            }

            if (! $this->withinDailyLimits($shopId, $visitorHash)) {
                return $this->fixed('limit', $locale);
            }

            return $this->ask($shopId, $subject, $question, $locale, $visitorHash);
        });
    }

    /**
     * The barrier before the saved answers and any model (see Barrier). Null when the question may go on.
     *
     * @return array{outcome: string, answer: string, from: string}|null
     */
    private function guarded(string $shopId, string $question, string $visitorHash, string $locale): ?array
    {
        $stop = Barrier::check($shopId, $question, $visitorHash, Barrier::ip(), $locale);

        if ($stop === null) {
            return null;
        }

        return isset($stop['answer'])
            ? ['outcome' => $stop['outcome'], 'answer' => $stop['answer'], 'from' => 'none']
            : $this->fixed($stop['outcome'], $locale);
    }

    /** @return array{outcome: string, answer: string, from: string, source?: string} */
    private function ask(string $shopId, Subject $subject, string $question, string $locale, string $visitorHash): array
    {
        $result = $this->fixed('unavailable', $locale);

        $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            trigger: RunTrigger::Webhook,
            input: [$subject->kind => $subject->externalId, 'question' => $question],
            work: function (RunContext $run) use ($shopId, $subject, $question, $locale, $visitorHash, &$result): void {
                $answerModel = (string) Settings::get('assistant.answer_model');
                $checkModel = (string) Settings::get('assistant.scope_model');
                $maxOutput = (int) Settings::get('assistant.answer_max_output_tokens');
                $effort = (string) Settings::get('assistant.reasoning_effort');
                $effort = $effort === 'model_default' ? null : $effort;

                // what the store's team wrote about the store and all its products holds for this one too
                $store = $subject->kind === Subject::PRODUCT ? $this->storeLines($shopId) : '';
                $withStore = $store === '' ? [] : ['store' => $store];
                $contextJson = (string) json_encode($subject->context, JSON_UNESCAPED_UNICODE).' '.$store;
                $estimatedInput = (int) ceil(mb_strlen($contextJson) / 2) + 800;
                /** @var list<array{0: ModelReply, 1: string}> $replies */
                $replies = [];

                try {
                    $this->spend->assertCanSpend(forShopper: true, estimatedUsd: (
                        $estimatedInput * $this->price('answer_input') + $maxOutput * $this->price('answer_output')
                        + ($estimatedInput + 1200) * $this->price('scope_input') + 2 * self::CHECK_OUTPUT_TOKENS * $this->price('scope_output')
                    ) / 1_000_000);

                    $scope = $this->call($run, $checkModel, $subject->prompt('scope'), [$subject->kind => $subject->brief, 'question' => $question], self::CHECK_OUTPUT_TOKENS, $effort);
                    $replies[] = [$scope, 'scope'];

                    if (($scope->data['about_product'] ?? null) !== true) {
                        $this->save($subject, $question, AssistantAnswer::OUT_OF_SCOPE, null, null, $checkModel, $replies, $run);
                        $result = $this->fixed('out_of_scope', $locale, 'model');
                        $run->output(['outcome' => AssistantAnswer::OUT_OF_SCOPE])->summary('assistant::runs.out_of_scope');

                        return;
                    }

                    $reply = $this->call($run, $answerModel, $subject->prompt('answer'), [$subject->kind => $subject->context, ...$withStore, 'question' => $question], $maxOutput, $effort, 'answer');
                    $replies[] = [$reply, 'answer'];

                    $answer = mb_substr(trim((string) ($reply->data['answer'] ?? '')), 0, self::MAX_ANSWER_CHARS);
                    $source = in_array($reply->data['source'] ?? null, ['store', 'general'], true) ? $reply->data['source'] : null;
                    $refusal = match (true) {
                        $source === null || $answer === '' => 'no_answer',
                        Question::hasContactDetails($answer) => 'contact_details',
                        self::mentionsPrice($answer) => 'price',
                        self::hasNumberNotIn($answer, $contextJson.' '.$question) => 'number_not_in_product_information',
                        default => null,
                    };

                    if ($refusal === null) {
                        $check = $this->call($run, $checkModel, $subject->prompt('verify'), [$subject->kind => $subject->context, ...$withStore, 'question' => $question, 'answer' => $answer], self::CHECK_OUTPUT_TOKENS, $effort);
                        $replies[] = [$check, 'scope'];

                        $refusal = match (false) {
                            ($check->data['about_this_product'] ?? null) === true => 'not_about_this_product',
                            ($check->data['consistent'] ?? null) === true => 'not_consistent',
                            ($check->data['on_topic'] ?? null) === true => 'off_topic',
                            default => null,
                        };
                    }
                } catch (SpendCapReached $e) {
                    $run->fail('assistant::runs.spend_cap', [], $e->getMessage());

                    return;
                } catch (ModelCallFailed $e) {
                    $run->fail('assistant::runs.model_failed', ['reason' => $e->reason], $e->getMessage());

                    return;
                }

                $outcome = $refusal === null ? AssistantAnswer::ANSWERED : AssistantAnswer::NO_INFO;
                // Clean: past the barrier, every code check and the checker; only such an answer
                // counts its visitor toward sharing it with everyone.
                $flags = ['safe' => $refusal === null, 'injection' => false, 'personal' => false, 'refused' => $refusal];
                $saved = $this->save($subject, $question, $outcome, $refusal === null ? $answer : null, $refusal === null ? $source : null, $answerModel, $replies, $run, $flags);

                if ($refusal === null && $saved->outcome === AssistantAnswer::ANSWERED) {
                    $saved->countVisitor($visitorHash, (int) Settings::get('assistant.share_after_visitors', $shopId));
                }

                $result = $refusal === null
                    ? ['outcome' => $outcome, 'answer' => $answer, 'from' => 'model', 'source' => (string) $source]
                    : $this->fixed('no_info', $locale, 'model');
                $run->output(['outcome' => $outcome, 'source' => $source, 'refused' => $refusal, 'answer' => $answer])->summary('assistant::runs.'.$outcome);
            },
        );

        return $result;
    }

    /** @param array<string, mixed> $input */
    private function call(RunContext $run, string $model, string $prompt, array $input, int $maxOutput, ?string $effort, string $prices = 'scope'): ModelReply
    {
        // The writer and the checker each call the provider the operator chose for them.
        $provider = AgentModel::provider("assistant.{$prices}_provider");
        $reply = $this->models->json($provider, $model, self::prompt($prompt), (string) json_encode($input, JSON_UNESCAPED_UNICODE), $maxOutput, $effort);
        $run->usage($provider->value, $model, $reply->inputTokens, $reply->outputTokens, 0, $reply->costUsd($this->price($prices.'_input'), $this->price($prices.'_output')));

        return $reply;
    }

    private function withinDailyLimits(string $shopId, string $visitorHash): bool
    {
        // From a storefront request, its address counts too; from the console there is none.
        return DailyQuestions::allow($shopId, $visitorHash, app()->runningInConsole() && ! app()->runningUnitTests() ? null : request()->ip());
    }

    /**
     * One saved answer per question on a page: a new visitor's answer replaces the one before, and
     * the visitors already counted stay. A refusal never replaces an answer others got clean.
     *
     * @param  list<array{0: ModelReply, 1: string}>  $replies  each reply with the prices it is charged at
     * @param  array<string, mixed>|null  $flags  what the checks said
     */
    private function save(Subject $subject, string $question, string $outcome, ?string $answer, ?string $source, string $model, array $replies, RunContext $run, ?array $flags = null): AssistantAnswer
    {
        $cost = 0.0;
        $input = 0;
        $output = 0;
        foreach ($replies as [$reply, $prices]) {
            $cost += $reply->costUsd($this->price($prices.'_input'), $this->price($prices.'_output'));
            $input += $reply->inputTokens;
            $output += $reply->outputTokens;
        }

        $saved = AssistantAnswer::query()->where($subject->column(), $subject->id)->where('question_key', Question::key($question))->first();

        if ($saved !== null && $saved->outcome === AssistantAnswer::ANSWERED && $outcome !== AssistantAnswer::ANSWERED) {
            return $saved;
        }

        $saved ??= new AssistantAnswer([
            'shop_id' => $subject->shopId,
            $subject->column() => $subject->id,
            'question_key' => Question::key($question),
            'asked_count' => 0,
            'visitors' => 0,
        ]);

        $saved->forceFill([
            'question' => $question,
            'answer' => $answer,
            'outcome' => $outcome,
            'source' => $source,
            'prompt_version' => self::PROMPT_VERSION,
            'model' => $model,
            'input_tokens' => $input,
            'output_tokens' => $output,
            'cost_usd' => round($cost, 6),
            'run_id' => $run->runId,
            'flags' => $flags,
            'last_asked_at' => now(),
        ])->save();

        return $saved;
    }

    private function price(string $name): float
    {
        return (float) Settings::get("assistant.{$name}_usd_per_million");
    }

    /** A price in an answer goes stale; the page shows the real one. */
    private static function mentionsPrice(string $answer): bool
    {
        return preg_match('~₪|ש"ח|ש״ח|\d+\s*שקל~u', $answer) === 1;
    }

    /** A number the store's information and the question do not have is a spec the model made up. */
    private static function hasNumberNotIn(string $answer, string $known): bool
    {
        preg_match_all('~\d+(?:[.,]\d+)?~u', $answer, $numbers);

        foreach ($numbers[0] as $number) {
            if (! str_contains($known, $number) && ! str_contains($known, str_replace(',', '.', $number))) {
                return true;
            }
        }

        return false;
    }

    /** The lines the store's team wrote in the knowledge centre, as the assistant reads them; empty when none or not read. */
    private function storeLines(string $shopId): string
    {
        if (! Features::enabled('assistant.knowledge', $shopId)) {
            return '';
        }

        return mb_substr(trim((string) $this->ownEntry()?->body), 0, self::STORE_CHARS);
    }

    /** Whether the team changed its own lines after the answer was written. */
    private function storeLinesChangedSince(AssistantAnswer $saved): bool
    {
        $changed = $this->ownEntry()?->updated_at;

        return $changed !== null && $saved->updated_at !== null && $saved->updated_at->lt($changed);
    }

    private function ownEntry(): ?CatalogContent
    {
        return CatalogContent::query()->active()->where('type', CatalogContent::KNOWLEDGE)->where('external_id', BuildShopKnowledge::OWN)->first(['id', 'body', 'updated_at']);
    }

    private static function prompt(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/../Prompts/'.$name.'.v'.self::PROMPT_VERSION.'.md');
    }

    /** Whether a question on a product's page is about the store (STORE_WORDS). */
    public static function aboutTheStore(string $question): bool
    {
        $text = strtr(mb_strtolower($question), ['ך' => 'כ', 'ם' => 'מ', 'ן' => 'נ', 'ף' => 'פ', 'ץ' => 'צ']);

        return preg_match('/(?<![\p{L}])[והבלש]?(?:'.implode('|', self::STORE_WORDS).')/u', $text) === 1;
    }

    /** Whether this product already has a shown answer to the question (the team's, or one written before). */
    private function answeredHere(string $externalId, string $question): bool
    {
        $productId = CatalogProduct::query()->where('external_id', $externalId)->value('id');

        return $productId !== null && AssistantAnswer::query()->where('product_id', $productId)->where('question_key', Question::key($question))
            ->where('status', AssistantAnswer::SHOWN)->where('outcome', AssistantAnswer::ANSWERED)->exists();
    }

    /**
     * The site's answer to a store question asked on a product's page, in this answerer's shape. Asked as
     * the store's question, not bound to the product: delivery and returns are the same on every page, so
     * the answer is saved and shared like one from the search box. Quotes from the store's pages come with
     * it when the answer could not be checked.
     *
     * @return array{outcome: string, answer: string, from: string, source?: string, sources?: list<array<string, mixed>>, quotes?: list<array<string, mixed>>}
     */
    private function bySite(string $shopId, string $question, string $visitorHash, string $locale): array
    {
        $site = app(AnswerSiteQuestion::class)->handle($shopId, $question, $visitorHash, $locale);

        return array_filter([
            'outcome' => (string) ($site['outcome'] ?? 'unavailable'),
            'answer' => (string) ($site['answer'] ?? ''),
            'from' => (string) ($site['from'] ?? 'none'),
            'source' => isset($site['source']) ? (string) $site['source'] : null,
            'sources' => ($site['sources'] ?? []) ?: null,
            'quotes' => ($site['quotes'] ?? []) ?: null,
        ], fn ($value): bool => $value !== null);
    }

    /** @return array{outcome: string, answer: string, from: string} */
    private function fixed(string $outcome, string $locale, string $from = 'none'): array
    {
        return ['outcome' => $outcome, 'answer' => (string) __('assistant::answers.'.$outcome, [], $locale), 'from' => $from];
    }
}
