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
use App\Modules\Ai\Contracts\StructuredChatModel;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Assistant\Support\AnswerLanguage;
use App\Modules\Assistant\Support\Barrier;
use App\Modules\Assistant\Support\DailyQuestions;
use App\Modules\Assistant\Support\Offenders;
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
 *    the store's information, on topic and safe to show any shopper. Only then the shopper sees it.
 *
 * Every call asks SpendGuard first and records tokens and cost on one run. Every answer is saved.
 */
final class AnswerQuestion
{
    public const AGENT = 'assistant.answerer';

    public const ACTION = 'assistant.answer';

    // v9 (17.11, as Aviad asked): a term of the trade asked on a product's page ("מה זה FG I1?") is explained from the store's
    // bank of terms (`terms`): in plain words, where it stands on its scale, this piece's details, and one closing sentence.
    // Compared on 16 term questions and 6 others from Rocks, Gueta, Naot and Vans, twice each: every term answer explained
    // and placed, shown 31 of 32 against 30; the others the same (docs/eval/answers-product-v9-vs-v8.json in Let Agents).
    // v10 (18.11): the writer is given what the product's picture shows (`picture`), and a word of the product's own name
    // that says how it is built or worn ("מה הכוונה שני חלקים?") is told as one explanation: what the parts are, where each
    // sits, what it gives. The answer had said the phrase again in other words, in three labelled lines. Compared on 25
    // questions from Rocks, Gueta, Naot, Vans and Take Me Home (docs/eval/answers-product-v10-vs-v9.json in Let Agents).
    // v11 (18.11, as Aviad asked, after setting an answer beside the store's other chat): the piece is sold as it is answered,
    // with the facts of its card a buyer weighs (its stones, their weight and grade, the metal, the width, a certificate) and
    // what the answer gives in wearing it, both ways ("רק החלק הקדמי למראה עדין, או עם המניפה למראה עשיר"). v10 had answered
    // "can I wear only the front?" with "sometimes" and no fact of the piece. Compared on 16 questions from 6 stores: shown 14
    // and 14, the answers fuller (docs/eval/answers-product-v11-vs-v10.json in Let Agents).
    public const PROMPT_VERSION = 11;

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

    /** Of STORE_WORDS, the ones that ask to resize this product: answered about it (asksToResize). */
    private const RESIZE_WORDS = ['להגד(?:י)?ל', 'להקט(?:י)?נ', 'הגדלה', 'הגדלת', 'הקטנה', 'הקטנת', 'התאמת מידה', 'שינוי מידה', 'resiz'];

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
            // Resizing is a service of the store, but how far depends on this product (a ring of 48 made 46 to 50): asked
            // about this product, with the sizes its page offers, and kept for no other (Rocks, 15.11: "אפשר להגדיל למידה
            // 59?" was answered from the store's general pages, and that answer was saved for every ring).
            if (! $onArticle && self::aboutTheStore($question) && Features::enabled('assistant.on_search', $shopId) && ! $this->answeredHere($externalId, $question)) {
                return $this->bySite($shopId, $question, $visitorHash, $locale, self::asksToResize($question) ? $externalId : null);
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
            // And one kept in another language than the question's (lets-review-test-4, 15.11): no answer to it.
            // And one written in another tone than the store chose since (assistant.answer_tone, its own words for it).
            if ($saved !== null && $saved->source !== 'team' && ($saved->prompt_version < self::PROMPT_VERSION || $this->storeLinesChangedSince($saved)
                || (((array) $saved->flags)['tone'] ?? null) !== AnswerSiteQuestion::toneKey($shopId)
                || (trim((string) $saved->answer) !== '' && ! AnswerLanguage::writtenIn((string) $saved->answer, AnswerLanguage::of($question, $locale))))) {
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

                // a kept "not about this page" is only ever that (harm and instructions are never kept)
                return $this->fixed($saved->outcome === AssistantAnswer::OUT_OF_SCOPE ? 'out_of_scope' : 'no_info', $locale, 'bank')
                    + ($saved->outcome === AssistantAnswer::OUT_OF_SCOPE ? ['reason' => 'not_about_page'] : []);
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
                // the product page's own writer (product_answer_model): its input is one product, small, and its answers read better on
                // gpt-5.4; the search box's writer is the faster one (answer_model), as Aviad decided 18.11
                $answerModel = (string) Settings::get('assistant.product_answer_model');
                $checkModel = (string) Settings::get('assistant.scope_model');
                $maxOutput = (int) Settings::get('assistant.answer_max_output_tokens');
                $effort = (string) Settings::get('assistant.reasoning_effort');
                $effort = $effort === 'model_default' ? null : $effort;

                // what the store's team wrote about the store and all its products holds for this one too
                $store = $subject->kind === Subject::PRODUCT ? $this->storeLines($shopId) : '';
                $withStore = $store === '' ? [] : ['store' => $store];
                // the store's checked explanations of the terms the question names (BuildTradeGlossary), for a prompt that reads
                // them (v9): "מה זה FG I1?" on earrings was told the letters alone (Rocks, 17.11). Their numbers are known too.
                $terms = $subject->kind === Subject::PRODUCT && self::PROMPT_VERSION >= 9 ? $this->termLines($shopId, $question) : '';
                $withTerms = $terms === '' ? [] : ['terms' => $terms];
                $contextJson = (string) json_encode($subject->context, JSON_UNESCAPED_UNICODE).' '.$store.' '.$terms;
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

                    // A question that asks for harm or tries to instruct the assistant (scope v6): counted against the
                    // visitor, and nothing is saved, so the next asker is checked again.
                    if (($scope->data['harmful'] ?? false) === true || ($scope->data['injection'] ?? false) === true) {
                        $reason = ($scope->data['harmful'] ?? false) === true ? 'harmful' : 'injection';
                        Offenders::record($shopId, $visitorHash, Barrier::ip());
                        $result = $this->fixed('out_of_scope', $locale, 'model');
                        $run->output(['outcome' => AssistantAnswer::OUT_OF_SCOPE, 'refused' => $reason])->summary('assistant::runs.out_of_scope');

                        return;
                    }

                    if (($scope->data['about_product'] ?? null) !== true) {
                        $this->save($subject, $question, AssistantAnswer::OUT_OF_SCOPE, null, null, $checkModel, $replies, $run);
                        // not about this page, and not harm: the site's assistant may answer it about the store (AskController)
                        $result = $this->fixed('out_of_scope', $locale, 'model') + ['reason' => 'not_about_page'];
                        $run->output(['outcome' => AssistantAnswer::OUT_OF_SCOPE])->summary('assistant::runs.out_of_scope');

                        return;
                    }

                    // the language to answer in, as code read it from the question; an answer in another is no answer (the
                    // site's assistant is asked next, AskController), never Hebrew to an English question (lets-review-test-4, 15.11)
                    $language = AnswerLanguage::of($question, $locale);
                    $reply = $this->call($run, $answerModel, $subject->prompt('answer'), [$subject->kind => $subject->context, ...$withStore, ...$withTerms, ...(($tone = AnswerSiteQuestion::tone($shopId)) === null ? [] : ['tone' => $tone]), 'question' => $question, 'language' => AnswerLanguage::name($language)], $maxOutput, $effort, 'answer');
                    $replies[] = [$reply, 'answer'];

                    $answer = mb_substr(trim((string) ($reply->data['answer'] ?? '')), 0, self::MAX_ANSWER_CHARS);
                    $source = in_array($reply->data['source'] ?? null, ['store', 'general'], true) ? $reply->data['source'] : null;
                    $refusal = match (true) {
                        $source === null || $answer === '' => 'no_answer',
                        ! AnswerLanguage::writtenIn($answer, $language) => 'wrong_language',
                        Question::hasContactDetails($answer) => 'contact_details',
                        self::mentionsPrice($answer) => 'price',
                        self::hasNumberNotIn($answer, $contextJson.' '.$question) => 'number_not_in_product_information',
                        default => null,
                    };

                    if ($refusal === null) {
                        $check = $this->call($run, $checkModel, $subject->prompt('verify'), [$subject->kind => $subject->context, ...$withStore, ...$withTerms, 'question' => $question, 'answer' => $answer], self::CHECK_OUTPUT_TOKENS, $effort);
                        $replies[] = [$check, 'scope'];

                        $refusal = match (false) {
                            ($check->data['about_this_product'] ?? null) === true => 'not_about_this_product',
                            ($check->data['consistent'] ?? null) === true => 'not_consistent',
                            ($check->data['on_topic'] ?? null) === true => 'off_topic',
                            ($check->data['safe'] ?? null) === true => 'not_safe',
                            ($check->data['injection'] ?? false) !== true => 'injection',
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
        $user = (string) json_encode($input, JSON_UNESCAPED_UNICODE);
        // Held to the schema beside the prompt, so the reply always has the shape the code reads.
        $schema = self::schema($prompt);
        $reply = $schema !== null && $this->models instanceof StructuredChatModel
            ? $this->models->structured($provider, $model, self::prompt($prompt), $user, $schema, $maxOutput, $effort)
            : $this->models->json($provider, $model, self::prompt($prompt), $user, $maxOutput, $effort);
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
            // the tone it was written in: written again when the store chooses another
            'flags' => (array) $flags + ['tone' => AnswerSiteQuestion::toneKey((string) $saved->shop_id)],
            'last_asked_at' => now(),
        ])->save();

        return $saved;
    }

    private function price(string $name): float
    {
        // the writer here is the product page's own, at its own prices (product_answer_*)
        $name = str_starts_with($name, 'answer_') ? 'product_'.$name : $name;

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

    /** Lines of the bank of terms, of those whose term the question names. */
    private const TERM_LINES = 6;

    /**
     * The lines of the store's bank of terms whose term the question names ("- FG: ..."), when the bank is on for the store
     * (or tried in a quality run's own process, assistant.try_terms); empty otherwise.
     */
    private function termLines(string $shopId, string $question): string
    {
        if (! Features::enabled('assistant.trade_terms', $shopId) && config('assistant.try_terms') !== true) {
            return '';
        }
        $body = (string) CatalogContent::query()->active()->where('type', CatalogContent::KNOWLEDGE)->where('external_id', BuildShopKnowledge::TERMS)->value('body');

        return $body === '' ? '' : implode("\n", array_slice(BuildTradeGlossary::named($question, explode("\n", $body)), 0, self::TERM_LINES));
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

    /** @return array<string, mixed>|null */
    private static function schema(string $name): ?array
    {
        $file = __DIR__.'/../Prompts/'.$name.'.v'.self::PROMPT_VERSION.'.schema.json';
        $schema = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($schema) ? $schema : null;
    }

    /** Whether a question on a product's page is about the store (STORE_WORDS). */
    public static function aboutTheStore(string $question): bool
    {
        $text = strtr(mb_strtolower($question), ['ך' => 'כ', 'ם' => 'מ', 'ן' => 'נ', 'ף' => 'פ', 'ץ' => 'צ']);

        return preg_match('/(?<![\p{L}])[והבלש]?(?:'.implode('|', self::STORE_WORDS).')/u', $text) === 1;
    }

    /** Whether a question on a product's page asks to make it bigger or smaller (RESIZE_WORDS): this product's, not every one's. */
    public static function asksToResize(string $question): bool
    {
        $text = strtr(mb_strtolower($question), ['ך' => 'כ', 'ם' => 'מ', 'ן' => 'נ', 'ף' => 'פ', 'ץ' => 'צ']);

        return preg_match('/(?<![\p{L}])[והבלש]?(?:'.implode('|', self::RESIZE_WORDS).')/u', $text) === 1;
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
     * it when the answer could not be checked. With $about, a question about this product (resizing it): asked
     * from its page, about it, and kept for no other.
     *
     * @return array{outcome: string, answer: string, from: string, source?: string, sources?: list<array<string, mixed>>, quotes?: list<array<string, mixed>>}
     */
    private function bySite(string $shopId, string $question, string $visitorHash, string $locale, ?string $about = null): array
    {
        $site = $about === null
            ? app(AnswerSiteQuestion::class)->handle($shopId, $question, $visitorHash, $locale)
            : app(AnswerSiteQuestion::class)->handle($shopId, $question, $visitorHash, $locale, [$about], [], true);

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
