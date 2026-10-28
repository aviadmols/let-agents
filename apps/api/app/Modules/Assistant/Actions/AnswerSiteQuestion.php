<?php

namespace App\Modules\Assistant\Actions;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Localization\Money;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\AgentModel;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Contracts\SpendCapReached;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Ai\Contracts\StructuredChatModel;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Assistant\Contracts\PriceRange;
use App\Modules\Assistant\Contracts\ProductsDescribed;
use App\Modules\Assistant\Contracts\ProductSets;
use App\Modules\Assistant\Contracts\ProductsNamed;
use App\Modules\Assistant\Contracts\ProductsPopular;
use App\Modules\Assistant\Contracts\TriesQuestions;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Assistant\Models\AssistantSearchAsk;
use App\Modules\Assistant\Support\AnswerContext;
use App\Modules\Assistant\Support\AnswerFailure;
use App\Modules\Assistant\Support\Barrier;
use App\Modules\Assistant\Support\DailyQuestions;
use App\Modules\Assistant\Support\Dated;
use App\Modules\Assistant\Support\NoAnswer;
use App\Modules\Assistant\Support\Offenders;
use App\Modules\Assistant\Support\Privacy;
use App\Modules\Assistant\Support\Question;
use App\Modules\Assistant\Support\QuestionGuard;
use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Retrieval\Contracts\Passages;
use App\Modules\Retrieval\Contracts\TopicQuestions;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;

/**
 * Answers a question typed in the store's search box, about the whole site rather than one page,
 * and picks from the products the search found the ones that truly fit. Only ever after the
 * shopper asked; never while typing.
 *
 * 1. The same question asked before on this site: the saved answer and picks, no model. "Not
 *    found" is asked again after assistant.site_retry_days, in case the store added it since.
 * 2. Contact details, too short, over the visitor's or the shop's daily questions: refused in code.
 * 3. The store's pages nearest to the question by meaning, and the products the shopper was shown.
 *    Neither: "not found", and no writing model is asked.
 * 4. One family writes a short answer from those only, and picks at most three of the products,
 *    each with why. Nothing that fits is a good answer.
 * 5. Code keeps only refs it gave, and refuses contact details, a price, or a number the pages,
 *    the products and the question do not have.
 * 6. A model of the other family checks the answer is in the pages and the picks truly fit.
 *    Picks that do not fit are dropped; an answer the pages do not hold is refused.
 * 7. With no answer and no pick, the shop's WhatsApp is offered (assistant.search_whatsapp).
 *
 * Every question asked is logged with what was shown and what came of it, for the shop's report
 * and the daily review. Every call asks SpendGuard first and records tokens and cost on one run.
 */
final class AnswerSiteQuestion implements TriesQuestions
{
    public const AGENT = 'assistant.site_answerer';

    public const ACTION = 'assistant.answer_site';

    /**
     * 4: the safety rules, the checker's safe and injection, and the topic check (scope_site).
     * 6: who wrote each passage, and the store team's own word answers first (answer_site only;
     * 5 is the facts-only writer the bench tries, never released here).
     * 11: the store speaks as itself, "יש לנו", never the shopper's "יש לכם" back (answer_site only).
     * 12: a condition on a yes comes first ("after the first three months"), never after "yes, at any time" (answer_site only).
     * 13: a reply in the conversation (its earlier questions and answers), the shopper spoken to in the plural, never as a
     * man or a woman, and an offer to advise ends in one question they can reply to (answer_site only).
     * 14: the names of what it recommends that are none of its products ("שיטאקה"), for the products that hold them (answer_site only).
     */
    public const PROMPT_VERSION = 15;

    /** Names an answer recommends that are none of its products, at most, and products shown for them, at most. */
    private const MAX_NAMES = 3;

    private const NAMED_SHOWN = 6;

    /** A reply carries this many of the conversation's questions and answers before it, at most, each cut to these lengths. */
    public const MAX_THREAD = 3;

    private const THREAD_QUESTION_CHARS = 300;

    private const THREAD_ANSWER_CHARS = 700;

    /** The run of the second look's other wordings of a question, by the small model. */
    public const SIMILAR_ACTION = 'assistant.similar_questions';

    /** Other wordings a question is searched with again, at most. */
    private const MAX_SIMILAR = 6;

    private const SIMILAR_OUTPUT_TOKENS = 200;

    /**
     * How a "not on the site" was looked for: the wider look, and the code checks an answer passes. A "not
     * found" kept by an older way (before a fee the policy states could be quoted) is looked for again.
     */
    public const LOOK_VERSION = 3;

    /** A fix by code keeps at least this share of the answer, or the answer is refused whole. */
    private const KEPT_SHARE = 0.4;

    /** An answer saying what was asked about is not among what it was given ("לא מופיע", "נמצא כאן רק"); not "not in stock". */
    private const SAYS_MISSING = '/(?<![\p{L}])(?:לא|אינו|אינה|אינם|אינן)\s+(?:מופיע|מופיעה|מופיעים|נמצא|נמצאת|נמצאים)(?!\s+(?:ב|כרגע\s+ב)מלאי)|(?:נמצא|נמצאת|מופיע|מופיעה|יש)\s+כאן\s+רק|אין\s+(?:לי\s+)?מידע\s+על|\bnot\s+(?:listed|found|among|in\s+(?:the|these)\s+results)\b|\bonly\s+(?:found|see|have)\b/iu';

    /** How much longer than the answer the checker's fix may be: it may correct, not add. */
    private const REVISED_GROWTH = 2;

    /** Products a question may name by their title, at most. */
    private const NAMED_PRODUCTS = 3;

    /** A word this many titles begin a word with names none of them ("ספר" in a bookshop). */
    private const NAMED_COMMON = 6;

    /** The least a title must score to be named: one word only it and one other hold. */
    private const NAMED_FLOOR = 0.5;

    /** Titles within this share of the best score are named with it. */
    private const NAMED_NEAR = 0.75;

    /** Wordings from code that make the model's unneeded. */
    private const ENOUGH_FROM_CODE = 2;

    /** Who wrote a knowledge entry, as the writer is told. */
    private const WRITTEN_BY = [
        BuildShopKnowledge::OWN => 'store_team',
        BuildShopKnowledge::TEAM => 'team_answers',
        BuildShopKnowledge::REPEATED => 'every_product_page',
        // What the posts say about the store: a post, as the writer is told of any page.
        BuildShopKnowledge::POSTS => null,
    ];

    /** Lines of what the posts say, of those that speak of the question. */
    private const POST_LINES = 8;

    private const SCOPE_OUTPUT_TOKENS = 600;

    /** How much of the question, in percent of its letters, its corrected wording must keep. */
    private const CORRECTED_MIN_SAME = 60;

    public const MAX_PRODUCTS = 12;

    /** Products a superlative answered in code shows. */
    private const MAX_COMPUTED = 3;

    /** Words a question wraps around the product it names. */
    private const NOT_NAMES = ['איזה', 'איזו', 'אילו', 'מה', 'מהו', 'מהי', 'ומה', 'ומהו', 'ואיזה', 'ואיזו', 'ואילו', 'ויש', 'הכי', 'יש', 'לכם', 'אצלכם', 'אפשר', 'בבקשה', 'שלכם', 'מתחת', 'פחות', 'יותר', 'מעל', 'which', 'what', 'the', 'most', 'your', 'under', 'below', 'over', 'between', 'than',
        // Words for the store and its products in general: "מה הכי זול בין המוצרים" names no product.
        'בין', 'מכל', 'ביותר', 'המוצרים', 'מוצרים', 'המוצר', 'מוצר', 'הפריטים', 'פריטים', 'הפריט', 'פריט', 'באתר', 'בחנות', 'האתר', 'החנות', 'שיש', 'כאן', 'משהו', 'דבר',
        'products', 'product', 'items', 'item', 'store', 'shop', 'site', 'all', 'have', 'you', 'here', 'something', 'anything',
        // Words for the products just shown: "איזה מהם הכי זול" names none either.
        'מהם', 'מהן', 'ביניהם', 'ביניהן', 'מביניהם', 'מתוכם', 'מתוכן', 'מאלה', 'מאלו', 'האלה', 'האלו', 'אלה', 'אלו', 'them', 'these', 'those', 'ones'];

    /** Words that point back at the products the conversation just showed. */
    private const REFERS = ['מהם', 'מהן', 'ביניהם', 'ביניהן', 'מביניהם', 'מתוכם', 'מתוכן', 'מאלה', 'מאלו', 'האלה', 'האלו', 'התוצאות', 'them', 'these', 'those', 'ones', 'results'];

    /** Words that ask about the whole store, whatever was shown before: "מה הכי זול באתר". */
    private const STORE_WIDE = ['באתר', 'בחנות', 'מכל', 'האתר', 'החנות', 'בכל', 'store', 'shop', 'site', 'all'];

    private const MIN_CHARS = 3;

    private const MAX_ANSWER_CHARS = 900;

    private const MAX_SOURCES = 3;

    /** A page quoted when an answer could not be checked: its best sentences, at most this long. */
    private const QUOTE_SENTENCES = 2;

    private const QUOTE_CHARS = 320;

    private const MAX_PICKS = 3;

    /** @var array<string, mixed>|null how the question being handled was answered, for its ask (logged()) */
    private ?array $answered = null;

    /** @var array<string, int> prompt name => the version a replay asks with */
    private array $promptVersions = [];

    /** @var array<string, mixed>|null what the writer is given in a replay on frozen data, instead of today's (snapshot()) */
    private ?array $frozen = null;

    /** @var list<string> in the second look, the other wordings the pages are searched with too (prepare()) */
    private array $widenWith = [];

    /** @var list<string> the named products' titles, as text that speaks of them must hold (prepare()) */
    private array $namedTitles = [];

    /** True in the second look: the question was already found to be the store's. */
    private bool $scoped = false;

    /** Whether the question being handled is within the day's questions, once asked (withinDailyLimits). */
    private ?bool $allowed = null;

    /** @var list<array{question: string, answer: string}> a reply's conversation before it, oldest first (handle()) */
    private array $conversation = [];

    /**
     * Whether the question is about the products already shown ("מה הכי זול?" after a search, "מה ההבדל ביניהם?"): then
     * no product found by its page joins them (prepare()). A reply in the conversation is not: "לבעיות שינה" is answered
     * from the store, Myco Dream's own page with its card (Mycolivia, 25.10: cited, no card).
     */
    private bool $onShown = false;

    /** A trial from the search check screen (trial()): the whole way a shopper's question goes, nothing of it kept. */
    private bool $trial = false;

    /** @var list<array<string, mixed>> in a trial, what each look gave the writer: the pages and the products */
    private array $looked = [];

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
        private readonly ChatModel $models,
        private readonly SpendGuard $spend,
        private readonly Passages $passages,
        private readonly AnswerContext $context,
        private readonly TopicQuestions $topicQuestions,
    ) {}

    /**
     * @param  list<string>  $products  external ids of the products the shopper was shown, best first
     * @param  list<string>  $previous  external ids of the products the conversation's turn before showed
     * @param  list<array{q?: mixed, a?: mixed}>  $thread  a reply to an answer: the questions and answers before it, oldest first
     * @return array<string, mixed> outcome: answered, no_match, no_info, out_of_scope, invalid, limit or
     *                              unavailable; from: bank, model or none; with sources, picks, ask_id and whatsapp;
     *                              context: "previous" when the question was answered about the turn before
     */
    public function handle(string $shopId, string $question, string $visitorHash, string $locale = 'he', array $products = [], array $previous = [], bool $fromPage = false, array $thread = []): array
    {
        $question = Question::clean($question, (int) Settings::get('assistant.max_question_chars', $shopId));
        // A reply to an answer ("כמה פעמים כדאי לקחת אותה?" after one about cordyceps): the topic check writes it to stand
        // alone with the conversation (scope_site v8), and the pages, the writer and the checker read that; the writer
        // sees the conversation too, and answers the reply only (answer_site v14). Never saved, never read from the
        // saved ones: its words alone mean nothing. A reply may be a word ("כן").
        $this->conversation = self::thread($thread);

        if (! Features::enabled('assistant.on_search', $shopId) || mb_strlen(Question::normalize($question)) < ($this->conversation === [] ? self::MIN_CHARS : 1)) {
            return $this->fixed('invalid', $locale);
        }

        $products = array_slice(array_values(array_unique(array_map('strval', $products))), 0, self::MAX_PRODUCTS);
        $previous = array_slice(array_values(array_unique(array_map('strval', $previous))), 0, self::MAX_PRODUCTS);
        // "מה הכי זול?" after a search for pressure washers is about those washers: the products of
        // the turn before stand in for what the question's own words found.
        $followUp = $previous !== [] && self::followsUp($question);
        $products = $followUp ? $previous : $products;

        // "What is special about this product?" asked on a product page is about that product: like a
        // question about the turn before, no saved answer is read or written, since the same words are
        // about another product on the next page (and a refusal saved before the product was synced
        // would answer every product).
        // And a question that points at the products in front of the shopper ("מה ההבדל ביניהם?", "איזה מהם הכי
        // משתלם?", the drawer's own "not sure" chips): its words are the same for every shopper and its products are
        // not, so it is never kept for the next one (one shopper's picks were served to everyone).
        $this->onShown = $followUp || ($products !== [] && self::pointsBack($question));
        $bound = $this->onShown || ($fromPage && $products !== []) || $this->conversation !== [];
        // The product whose page it is: the writer says what matters about it.
        $viewing = $fromPage && ! $followUp && $products !== [] ? $products[0] : null;

        return $this->tenant->run($shopId, function () use ($shopId, $question, $visitorHash, $locale, $products, $followUp, $bound, $viewing): array {
            $this->answered = null;
            $this->allowed = null;
            $result = $this->guarded($shopId, $question, $visitorHash, $locale)
                ?? $this->answer($shopId, $question, $visitorHash, $locale, $products, $bound, $viewing);
            $result += $followUp && $result['outcome'] === AssistantAnswer::ANSWERED ? ['context' => 'previous'] : [];
            // The page the answer rests on that the question names ("מנויים" for "מה זה תוכנית מנויים?"): a button to it.
            $page = $result['outcome'] === AssistantAnswer::ANSWERED ? self::namedPage($question, (array) ($result['sources'] ?? [])) : null;
            $result += $page === null ? [] : ['page' => $page];
            // The price the question names, as code read it: what the storefront suggests beside "not found" keeps to it.
            $range = app()->bound(PriceRange::class) ? app(PriceRange::class)->read($question) : ['min' => null, 'max' => null];
            $result += $range['min'] === null && $range['max'] === null ? [] : ['range' => $range];

            return $this->logged($shopId, $question, $products, $result);
        });
    }

    /**
     * A question asked from the search check screen, the whole way a shopper's goes (the barrier, code, a set, the saved
     * answers, the pages, the writer and the checker), as a new visitor. Nothing of it is kept: no saved answer written
     * or counted, no ask logged, no visitor's daily questions used, no one marked an offender. Every model call is still
     * paid for and accounted (SpendGuard, the ledger, the run). With what code saw on the way, for the screen.
     *
     * @param  list<string>  $products  external ids of the products the search showed, best first
     * @return array<string, mixed> handle()'s result, with trial: the run, the models, the verdict, why not, and what each look gave the writer
     */
    public function trial(string $shopId, string $question, array $products, string $locale): array
    {
        $this->trial = true;
        $this->looked = [];

        try {
            return $this->handle($shopId, $question, 'trial:'.Str::random(20), $locale, $products);
        } finally {
            $this->trial = false;
            $this->looked = [];
        }
    }

    /**
     * The barrier before everything else (see Barrier). Null when the question may go on.
     *
     * @return array<string, mixed>|null
     */
    private function guarded(string $shopId, string $question, string $visitorHash, string $locale): ?array
    {
        // a trial is no visitor: the team's own address is never marked an offender for what it tried
        $stop = Barrier::check($shopId, $question, $visitorHash, $this->trial ? null : Barrier::ip(), $locale);

        if ($stop === null) {
            return null;
        }

        return isset($stop['answer'])
            ? ['outcome' => $stop['outcome'], 'answer' => $stop['answer'], 'from' => 'none', 'reason' => $stop['reason']]
            : $this->fixed($stop['outcome'], $locale) + ['reason' => $stop['reason']];
    }

    /** Answers that belong to the whole site: asked in the search box, not on a page. */
    public static function siteAnswers(): Builder
    {
        return AssistantAnswer::query()->whereNull('product_id')->whereNull('content_id');
    }

    /**
     * A question about the store answered ahead of any shopper (PrepareStoreAnswers), the same way a
     * shopper's is: the store's pages, one family writes, the other checks. Inside the shop's tenant.
     *
     * Asked once: an answer stays until a page it was written from changes; "not on the site" until the
     * site changes or the days pass (stale). The team's answers are never touched. An answer that passed
     * its check clean and safe is the shop's at once, shown to every shopper without a model; the call
     * is background work, held to the shop's allowance.
     *
     * @return string skipped | answered | not_answered | unavailable
     */
    public function prepareForStore(string $shopId, string $question, string $locale): string
    {
        $question = Question::clean($question, (int) Settings::get('assistant.max_question_chars', $shopId));
        $saved = self::siteAnswers()->where('question_key', Question::key($question))->first();

        if ($saved !== null && ($saved->source === 'team'
            || ($saved->outcome === AssistantAnswer::ANSWERED && $saved->prompt_version >= self::PROMPT_VERSION && ! $this->pagesChangedSince($saved))
            || ($saved->outcome !== AssistantAnswer::ANSWERED && ! $this->stale($saved, $shopId)))) {
            return 'skipped';
        }

        // Written from pages that changed since, or from an older prompt: written again, from what the pages say now.
        $saved?->delete();
        $result = $this->ask($shopId, $question, $locale, [], forShopper: false);

        if ($result['outcome'] !== AssistantAnswer::ANSWERED) {
            return in_array($result['outcome'], ['unavailable', 'limit'], true) ? 'unavailable' : 'not_answered';
        }

        $fresh = self::siteAnswers()->where('question_key', Question::key($question))->first();
        $flags = (array) ($fresh?->flags ?? []);
        if ($fresh !== null && ($flags['safe'] ?? null) === true && ! ($flags['injection'] ?? false) && ! ($flags['harmful'] ?? false) && ! ($flags['personal'] ?? false)) {
            $fresh->forceFill(['shared_at' => now(), 'flags' => $flags + ['prepared' => true]])->save();
        }

        return 'answered';
    }

    /** Whether a page an answer was written from changed after it was written. */
    private function pagesChangedSince(AssistantAnswer $saved): bool
    {
        $urls = array_values(array_filter(array_map(fn ($s): ?string => is_array($s) ? ($s['url'] ?? null) : null, (array) $saved->sources)));
        $changed = $urls === [] ? null : CatalogContent::query()->active()->whereIn('url', $urls)->max('updated_at');

        return $changed !== null && $saved->updated_at !== null && $saved->updated_at->lt(Carbon::parse($changed));
    }

    /**
     * @param  list<string>  $products
     * @param  bool  $followUp  the products are the turn before's: no saved answer is read or written,
     *                          since the same words were about other products for anyone else
     * @return array<string, mixed>
     */
    private function answer(string $shopId, string $question, string $visitorHash, string $locale, array $products, bool $followUp = false, ?string $viewing = null): array
    {
        // "The cheapest drill" is arithmetic: code answers it from the products found, before any
        // saved answer (prices and stock change) and without a model.
        if (($computed = $this->computed($question, $locale, $products, $followUp)) !== null) {
            return $computed;
        }

        if ($followUp) {
            return $this->withinDailyLimits($shopId, $visitorHash)
                ? $this->ask($shopId, $question, $locale, $products, visitorHash: $visitorHash, keep: false, viewing: $viewing)
                : $this->fixed('limit', $locale);
        }

        // "תציג לי את ספריית שיבולת", "תן לי את כל ספרי החטופים": every product of the set, not three
        // picks (ProductSets, from the Thinking module), before a saved answer that picked three.
        if ($viewing === null && ($set = $this->set($shopId, $question, $visitorHash, $locale)) !== null) {
            return $set;
        }

        $saved = self::siteAnswers()->where('question_key', Question::key($question))->first();

        if ($saved !== null && $this->stale($saved, $shopId)) {
            $saved->delete();
            $saved = null;
        }

        $answered = $saved !== null && $saved->status === AssistantAnswer::SHOWN && $saved->outcome === AssistantAnswer::ANSWERED;

        // A written answer is everyone's once it is shared (enough different visitors got it clean,
        // or the team approved it). Before that, only a visitor who already got it gets it again,
        // and asking again changes nothing; a new visitor is answered afresh, through every check.
        if ($answered && ($saved->isShared() || $saved->askedBy($visitorHash))) {
            if (! $this->trial) {
                $saved->countVisitor($visitorHash, (int) Settings::get('assistant.share_after_visitors', $shopId));
            }

            return [
                'outcome' => AssistantAnswer::ANSWERED,
                'answer' => (string) $saved->answer,
                'from' => 'bank',
                'sources' => (array) $saved->sources,
                'picks' => $this->present((array) $saved->picks),
                'answer_id' => $saved->id,
            ] + $this->describedFor((array) ($saved->flags['names'] ?? []), (array) $saved->picks);
        }

        // "Not found" and "not ours to answer" are the same for everyone, and show nothing written.
        if ($saved !== null && ! $answered) {
            if (! $this->trial) {
                $saved->forceFill(['last_asked_at' => now()])->save();
            }
            $outcome = $saved->outcome === AssistantAnswer::OUT_OF_SCOPE ? 'out_of_scope' : ($products === [] ? 'no_info' : 'no_match');

            return $this->fixed($outcome, $locale, 'bank') + ['answer_id' => $saved->id]
                + ($saved->outcome === AssistantAnswer::NO_INFO && trim((string) $saved->answer) !== '' ? ['not_found' => (string) $saved->answer] : []);
        }

        if (! $this->withinDailyLimits($shopId, $visitorHash)) {
            return $this->fixed('limit', $locale);
        }

        return $this->ask($shopId, $question, $locale, $products, visitorHash: $visitorHash);
    }

    private const PAGES_BY_WORDS = 3;

    /** Of the products whose own page the question found by meaning, how many join those the search showed. */
    private const FROM_PASSAGES = 3;

    /** How much of a page whose title the question names is read: the article whole, not the piece the meaning found. */
    private const ARTICLE_CHARS = 6000;

    /** Of the team's answers, how many like the question are read; and how much of an entry. */
    private const TEAM_PAIRS = 4;

    private const KNOWLEDGE_CHARS = 2500;

    /** @return list<string> */
    private function words(string $question): array
    {
        return Question::words($question);
    }

    /**
     * The products a question names by their title, from the whole catalogue, the best named alone.
     * Each word of the question weighs by how few titles begin a word with it (1 for one title, ½ for
     * two; none past NAMED_COMMON titles: "ספר" names every book), half again when it is only in the
     * title's subtitle ("הביוגרפיה" in "אשת הברזל - הביוגרפיה של…"); and a title whose name itself the
     * question says whole ("ביבי" of "ביבי: סיפור חיי", "המדינה שבדרך") scores one more. Only the
     * titles near the best score are named: "על מה הספר הביוגרפיה של ביבי?" names "ביבי: סיפור חיי", not
     * Thatcher's biography; "המדינה שבדרך" not "המדינה הזאת". Their own descriptions then reach the
     * writer. Code only.
     *
     * @return list<string> external ids, best first
     */
    private function namedProducts(string $question): array
    {
        $tokens = array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($question)) ?: [], fn (string $t): bool => $t !== ''));
        $scores = [];
        $titles = [];
        $startsWord = fn (string $text, string $word): bool => preg_match('/(?<![\p{L}\p{N}])ה?'.preg_quote($word, '/').'/u', $text) === 1;

        foreach ($tokens as $token) {
            $matched = [];
            foreach (Question::words($token) as $word) {
                $found = CatalogProduct::query()->whereNull('removed_at')
                    // The word, or the word with "ה" before it ("העדן" for "עדן"), beginning a word of the title.
                    ->where(fn ($q) => $q->whereRaw('lower(title) like ?', [$word.'%'])->orWhereRaw('lower(title) like ?', ['% '.$word.'%'])
                        ->orWhereRaw('lower(title) like ?', ['ה'.$word.'%'])->orWhereRaw('lower(title) like ?', ['% ה'.$word.'%']))
                    ->limit(self::NAMED_COMMON + 1)->get(['external_id', 'title']);
                foreach ($found as $product) {
                    $id = (string) $product->external_id;
                    $titles[$id] = (string) $product->title;
                    // In the name itself, or only in its subtitle.
                    $matched[$id] = max($matched[$id] ?? 0, $startsWord(self::titleCore($titles[$id]), $word) ? 1.0 : 0.5);
                }
            }

            // A word many titles hold says nothing of which one is meant.
            if ($matched === [] || count($matched) > self::NAMED_COMMON) {
                continue;
            }

            foreach ($matched as $id => $part) {
                $scores[$id] = ($scores[$id] ?? 0) + $part / count($matched);
            }
        }

        // A title the question names whole: every word of its name is a word of the question (a prefix before it too).
        $asked = ' '.implode(' ', $tokens).' ';
        foreach (array_keys($scores) as $id) {
            $words = array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', self::titleCore($titles[$id])) ?: [], fn (string $w): bool => mb_strlen($w) >= 2));
            // ("העדן" is named by "עדן": the title's own "ה" may be left out)
            if ($words !== [] && array_filter($words, fn (string $w): bool => preg_match('/\s[והבלמשכ]{0,2}'.preg_quote($w, '/').'\s/u', $asked) !== 1
                && ! (mb_strlen($w) > 3 && str_starts_with($w, 'ה') && preg_match('/\s[והבלמשכ]{0,2}'.preg_quote(mb_substr($w, 1), '/').'\s/u', $asked) === 1)) === []) {
                $scores[$id] += 1;
            }
        }

        if ($scores === [] || max($scores) < self::NAMED_FLOOR) {
            return [];
        }

        $best = max($scores);
        $named = array_keys(array_filter($scores, fn (float $score): bool => $score >= $best * self::NAMED_NEAR));
        usort($named, fn (string|int $a, string|int $b): int => [$scores[$b], mb_strlen($titles[(string) $a])] <=> [$scores[$a], mb_strlen($titles[(string) $b])]);

        return array_slice(array_map('strval', $named), 0, self::NAMED_PRODUCTS);
    }

    /**
     * The products an answer names that are not picked yet, in the order it names them, each a pick with the sentence
     * that names it as its why, up to the picks' limit. Only of the products the writer was given ($productsByRef). A name
     * counts in quotes ("דרקולה"), or whole (never inside another word, maybe after a prefix letter) when it is long
     * enough not to be a common word: two words or more, or five letters.
     *
     * @param  list<array{ref: string, external_id: string, title: string, why: string}>  $picks
     * @param  array<string, array<string, mixed>>  $productsByRef
     * @return list<array{ref: string, external_id: string, title: string, why: string}>
     */
    public static function namedInAnswer(string $answer, array $picks, array $productsByRef): array
    {
        $picked = array_flip(array_column($picks, 'ref'));
        $sentences = preg_split('/(?<=[.!?])\s+/u', trim($answer)) ?: [];
        $lower = mb_strtolower($answer);
        $found = [];

        foreach ($productsByRef as $ref => $product) {
            $core = self::titleCore((string) ($product['title'] ?? ''));
            if (isset($picked[$ref]) || mb_strlen($core) < 2) {
                continue;
            }
            $name = preg_quote($core, '/');
            $quoted = '/["״«“\']\s*'.$name.'/u';
            $whole = '/(?<![\p{L}\p{N}])[והבלמשכ]?'.$name.'(?![\p{L}\p{N}])/u';
            $long = str_contains($core, ' ') || mb_strlen($core) >= 5;

            foreach ($sentences as $sentence) {
                $said = mb_strtolower($sentence);
                if (preg_match($quoted, $said) === 1 || ($long && preg_match($whole, $said) === 1)) {
                    $why = trim((string) preg_replace('/\s+/u', ' ', $sentence));
                    $found[] = ['at' => (int) mb_strpos($lower, $core), 'pick' => [
                        'ref' => (string) $ref, 'external_id' => (string) $product['external_id'], 'title' => (string) $product['title'],
                        'why' => mb_strlen($why) > 160 ? rtrim(mb_substr($why, 0, 159)).'…' : $why,
                    ]];
                    break;
                }
            }
        }

        usort($found, fn (array $a, array $b): int => $a['at'] <=> $b['at']);
        foreach ($found as $named) {
            if (count($picks) >= self::MAX_PICKS) {
                break;
            }
            $picks[] = $named['pick'];
        }

        return $picks;
    }

    /**
     * The products whose own page an answer cites, picked when they are not yet, in the order cited, up to the picks'
     * limit. Only of the products the writer was given; each goes to the checker like any pick, with no reason of
     * its own (the checker writes one, or drops it).
     *
     * @param  list<string>  $cited
     * @param  array<string, array<string, mixed>>  $byRef
     * @param  list<array{ref: string, external_id: string, title: string, why: string}>  $picks
     * @param  array<string, array<string, mixed>>  $productsByRef
     * @return list<array{ref: string, external_id: string, title: string, why: string}>
     */
    public static function citedProducts(array $cited, array $byRef, array $picks, array $productsByRef): array
    {
        $refOf = [];
        foreach ($productsByRef as $ref => $product) {
            $refOf[(string) $product['external_id']] = (string) $ref;
        }
        $picked = array_flip(array_column($picks, 'external_id'));

        foreach ($cited as $s) {
            $passage = $byRef[$s] ?? null;
            $id = (string) ($passage['external_id'] ?? '');
            if (count($picks) >= self::MAX_PICKS) {
                break;
            }
            if (($passage['source'] ?? null) !== 'product' || $id === '' || isset($picked[$id]) || ! isset($refOf[$id])) {
                continue;
            }
            $picked[$id] = true;
            $picks[] = ['ref' => $refOf[$id], 'external_id' => $id, 'title' => (string) $productsByRef[$refOf[$id]]['title'], 'why' => ''];
        }

        return $picks;
    }

    /**
     * The products an answer may show, with those whose own page it cites that it was not given, each under a new ref:
     * the checker reads them as any pick. Within a price the question asks, as the rest.
     *
     * @param  list<string>  $cited
     * @param  array<string, array<string, mixed>>  $byRef
     * @param  array<string, array<string, mixed>>  $productsByRef
     * @return array<string, array<string, mixed>>
     */
    private function withCited(string $question, array $cited, array $byRef, array $productsByRef): array
    {
        $given = array_map(fn (array $p): string => (string) ($p['external_id'] ?? ''), $productsByRef);
        $missing = [];
        foreach ($cited as $ref) {
            $id = ($byRef[$ref]['source'] ?? null) === 'product' ? (string) ($byRef[$ref]['external_id'] ?? '') : '';
            if ($id !== '' && ! in_array($id, $given, true) && ! in_array($id, $missing, true)) {
                $missing[] = $id;
            }
        }
        if ($missing === []) {
            return $productsByRef;
        }
        ['min' => $min, 'max' => $max] = app()->bound(PriceRange::class) ? app(PriceRange::class)->read($question) : ['min' => null, 'max' => null];
        $n = count($productsByRef);
        foreach ($this->candidates($missing) as $product) {
            if (($min !== null || $max !== null) && ! ($product['price'] !== null && $product['price'] > 0 && ($min === null || $product['price'] >= $min) && ($max === null || $product['price'] <= $max))) {
                continue;
            }
            $productsByRef['p'.(++$n)] = $product;
        }

        return $productsByRef;
    }

    /** A title as other text names it: before its subtitle or its code ("חטוף - אלי שרעבי" is "חטוף"). */
    private static function titleCore(string $title): string
    {
        $core = trim((string) preg_split('/\s[-–—|:]\s|:\s/u', $title)[0]);

        return mb_strtolower(mb_strlen($core) >= 3 ? $core : trim($title));
    }

    private function pagesByWords(string $question, array $found): array
    {
        $words = [...$this->words($question), ...self::topicWords($question)];

        // A question about a named product reads the pages that name it, never every page sharing a word.
        if ($this->namedTitles !== []) {
            $words = $this->namedTitles;
        }

        if ($words === []) {
            return $found;
        }

        $scores = [];

        // lower(): Postgres compares case, and Shopify's policies are titled "Refund policy".
        foreach (array_unique($words) as $word) {
            foreach (CatalogContent::query()->active()->where('type', '!=', CatalogContent::KNOWLEDGE)->whereRaw('lower(title) like ?', ['%'.$word.'%'])->limit(10)->pluck('id') as $id) {
                $scores[(string) $id] = ($scores[(string) $id] ?? 0) + 1;
            }
        }

        arsort($scores);
        $ids = array_slice(array_map('strval', array_keys($scores)), 0, self::PAGES_BY_WORDS);
        $read = CatalogContent::query()->whereIn('id', $ids)->get(['id', 'title', 'body'])->keyBy(fn (CatalogContent $c): string => (string) $c->id);
        $pages = [];

        // A page the question names by its title and the meaning found too is read whole, once ("מה הכי טוב לסרטן?" reads
        // the article "פטריות מרפא וסרטן", not the two pieces of it the meaning found), in place of its pieces; a page only
        // the words found ("טוב" in a title) as far as its opening, and one the meaning found too after the first by its pieces.
        $whole = false;
        foreach ($ids as $id) {
            $pieces = array_filter($found, fn (array $p): bool => $p['source'] === 'content' && (string) $p['source_id'] === $id);
            if (($page = $read->get($id)) === null || ($whole && $pieces !== [])) {
                continue;
            }
            $chars = $pieces !== [] ? self::ARTICLE_CHARS : 1500;
            $whole = $whole || $pieces !== [];
            $found = array_values(array_filter($found, fn (array $p): bool => ! ($p['source'] === 'content' && (string) $p['source_id'] === $id)));
            $pages[] = ['source' => 'content', 'source_id' => $id, 'external_id' => null, 'title' => (string) $page->title, 'text' => mb_substr(trim((string) $page->body), 0, $chars), 'similarity' => 1.0, 'kind' => 'title'];
        }

        return [...$pages, ...$found];
    }

    /**
     * The words of every store topic the question is on (Hebrew final letters read as regular, and
     * without the י a verb adds: "להחזיר" is on החזר, "להחליף" on החלפ).
     *
     * @return list<string>
     */
    private static function topicWords(string $question): array
    {
        $plain = fn (string $text): string => str_replace('י', '', strtr(mb_strtolower($text), ['ך' => 'כ', 'ם' => 'מ', 'ן' => 'נ', 'ף' => 'פ', 'ץ' => 'צ']));
        $text = $plain($question);
        $words = [];

        foreach (PrepareStoreAnswers::TOPICS as $said) {
            foreach ($said as $word) {
                if (mb_strpos($text, $plain($word)) !== false) {
                    array_push($words, ...$said);
                    break;
                }
            }
        }

        return $words;
    }

    /**
     * The store's knowledge entries as the first passages: what its product pages repeat and what
     * its team wrote, whole; of the team's answers, only those that share a word with the question.
     */
    private function knowledge(string $question, string $shopId, array $found): array
    {
        if (! Features::enabled('assistant.knowledge', $shopId)) {
            return $found;
        }

        $words = $this->words($question);
        $first = [];

        // The team's own words first, then its answers, then what every product page repeats, then
        // what the posts say.
        $order = array_flip(array_keys(self::WRITTEN_BY));
        $entries = CatalogContent::query()->active()->where('type', CatalogContent::KNOWLEDGE)->orderBy('external_id')->get(['id', 'external_id', 'title', 'body'])
            ->sortBy(fn (CatalogContent $entry): int => $order[(string) $entry->external_id] ?? count($order));
        // About a named product: what names it, not every line sharing a word with the question ("מדינה" in
        // "המדינה הזאת" when "המדינה שבדרך" is asked about).
        $words = $this->namedTitles !== [] ? $this->namedTitles : $words;
        $speaks = function (string $piece) use ($words): bool {
            foreach ($words as $word) {
                if (mb_stripos($piece, $word) !== false) {
                    return true;
                }
            }

            return false;
        };

        foreach ($entries as $entry) {
            $text = trim((string) $entry->body);

            if ($entry->external_id === BuildShopKnowledge::TEAM) {
                $text = implode("\n\n", array_slice(array_filter(preg_split('/\n{2,}/u', $text) ?: [], $speaks), 0, self::TEAM_PAIRS));
            }

            if ($entry->external_id === BuildShopKnowledge::POSTS) {
                $text = implode("\n", array_slice(array_filter(explode("\n", $text), $speaks), 0, self::POST_LINES));
            }

            // what every page repeats, without a banner's line whose day is gone ("המשלוח הבא יוצא ב־15.9"):
            // read since the pages were, it would be told as the store's word today
            if ($entry->external_id === BuildShopKnowledge::REPEATED) {
                $text = implode("\n", array_filter(explode("\n", $text), fn (string $line): bool => ! Dated::past($line)));
            }

            if ($text !== '') {
                $first[] = ['source' => 'content', 'source_id' => (string) $entry->id, 'external_id' => null, 'title' => (string) $entry->title, 'text' => mb_substr($text, 0, self::KNOWLEDGE_CHARS), 'similarity' => 1.0, 'kind' => 'knowledge', 'from' => self::WRITTEN_BY[(string) $entry->external_id] ?? null]
                    // Cut to what speaks of the question: kept for a question about a product too.
                    + ($entry->external_id === BuildShopKnowledge::POSTS ? ['speaks' => true] : []);
            }
        }

        $ids = array_map(fn (array $p): string => $p['source_id'], $first);

        return [...$first, ...array_values(array_filter($found, fn (array $p): bool => $p['source'] !== 'content' || ! in_array($p['source_id'], $ids, true)))];
    }

    private function stale(AssistantAnswer $saved, string $shopId): bool
    {
        if ($saved->source === 'team') {
            return false;
        }

        if ($saved->prompt_version < self::PROMPT_VERSION) {
            return true;
        }

        // An answer stands until a page it was written from, or a product it picked, changes: never for good. One that
        // recommends products (its cards, or the products' pages it rests on) is written again once a product is added
        // after it: a new book may answer "ספרים על חיים טובים?" better (as Aviad asked, 26.10). On being asked, never before.
        if ($saved->outcome === AssistantAnswer::ANSWERED) {
            $picked = array_values(array_filter(array_map(fn ($p): string => is_array($p) ? (string) ($p['external_id'] ?? '') : '', (array) $saved->picks)));
            $changed = $picked === [] ? null : CatalogProduct::query()->whereIn('external_id', $picked)->max('updated_at');
            $products = $picked !== [] || in_array('product', array_column(array_filter((array) $saved->sources, 'is_array'), 'type'), true);
            $added = $products ? CatalogProduct::query()->active()->max('created_at') : null;
            $after = fn (mixed $at): bool => $at !== null && $saved->updated_at !== null && $saved->updated_at->lt(Carbon::parse($at));

            return $this->pagesChangedSince($saved) || $after($changed) || $after($added);
        }

        // "Not ours to answer" is looked at again after the days a "not found" waits.
        if ($saved->outcome !== AssistantAnswer::NO_INFO) {
            return $saved->updated_at === null || $saved->updated_at->lt(now()->subDays((int) Settings::get('assistant.site_retry_days', $shopId)));
        }

        // "Not on the site" holds until the store says more, and only then is a model asked again: a page
        // or post added or changed since (a cancellation policy written later), an answer of the team's
        // own written or changed since, a product added since, or the scan learned more of the products (a
        // book's genre: "פנטזיה" was not there, and now is). Not a day passing, not the index read again for
        // the night. And only once the question was also asked in other ways (the second look).
        $after = fn (mixed $at): bool => $at !== null && $saved->updated_at !== null && $saved->updated_at->lt(Carbon::parse($at));

        return $after(CatalogContent::query()->active()->max('updated_at'))
            || $after(AssistantAnswer::query()->where('source', 'team')->max('updated_at'))
            || $after(CatalogProduct::query()->active()->max('created_at'))
            || (((array) $saved->flags)['learned'] ?? null) !== self::learned()
            || ((int) (((array) $saved->flags)['looked'] ?? 0) < self::LOOK_VERSION && Features::enabled('assistant.second_look', $shopId));
    }

    /**
     * One question answered as the search box would, but without the saved answers, the daily
     * limits, the shopper's log, or saving the answer: for comparing context modes on fixed
     * questions. The run is recorded (its cost is real) and marked as an evaluation.
     *
     * @param  list<string>  $products  external ids shown, best first
     * @return array{result: array<string, mixed>, trace: array<string, mixed>}
     */
    public function evaluate(string $shopId, string $question, array $products, string $mode, string $locale = 'he'): array
    {
        $question = Question::clean($question, (int) Settings::get('assistant.max_question_chars', $shopId));
        $products = array_slice(array_values(array_unique(array_map('strval', $products))), 0, self::MAX_PRODUCTS);

        return $this->tenant->run($shopId, function () use ($shopId, $question, $products, $mode, $locale): array {
            // The same barrier as a shopper meets, without counting anyone or blocking anyone.
            $guard = QuestionGuard::inspect($question);
            if ($guard['action'] === QuestionGuard::SUPPORT) {
                return ['result' => ['outcome' => 'support', 'answer' => QuestionGuard::supportText($locale), 'from' => 'none'], 'trace' => ['from' => 'guard', 'refused' => 'support']];
            }
            if ($guard['action'] === QuestionGuard::REFUSE) {
                return ['result' => $this->fixed($guard['reason'] === 'personal_data' ? 'personal_data' : 'out_of_scope', $locale), 'trace' => ['from' => 'guard', 'refused' => $guard['reason']]];
            }

            if (($computed = $this->computed($question, $locale, $products)) !== null) {
                return ['result' => $computed, 'trace' => ['from' => 'code']];
            }

            $trace = [];
            $result = $this->ask($shopId, $question, $locale, $products, $mode, false, $trace);

            return ['result' => $result, 'trace' => $trace];
        });
    }

    /**
     * What the writing model would be given, in each mode, with why each product and passage was
     * kept: no model is called. For the dry evaluation.
     *
     * @param  list<string>  $products
     * @return array<string, array<string, mixed>> by mode
     */
    public function preview(string $shopId, string $question, array $products): array
    {
        $question = Question::clean($question, (int) Settings::get('assistant.max_question_chars', $shopId));
        $products = array_slice(array_values(array_unique(array_map('strval', $products))), 0, self::MAX_PRODUCTS);

        return $this->tenant->run($shopId, function () use ($shopId, $question, $products): array {
            $out = [];

            foreach ([AnswerContext::FULL, AnswerContext::DYNAMIC] as $mode) {
                $prepared = $this->prepare($shopId, $question, $products, $mode);
                $out[$mode] = [
                    'type' => $prepared['type'],
                    'empty' => $prepared['empty'],
                    'products' => array_map(fn (array $p): array => ['external_id' => $p['external_id'], 'title' => $p['title']], array_values($prepared['productsByRef'])),
                    'passages' => array_map(fn (array $p): array => ['title' => $p['title'], 'kind' => $p['kind'] ?? 'meaning', 'chars' => mb_strlen((string) $p['text'])], array_values($prepared['byRef'])),
                    'input_chars' => mb_strlen($prepared['input']),
                    'estimated_input_tokens' => (int) ceil(mb_strlen($prepared['input']) / 2) + 600,
                    'debug' => $prepared['debug'],
                ];
            }

            return $out;
        });
    }

    /**
     * What the writer is given for this question: the passages and products found, chosen in
     * dynamic mode, numbered, read by code, and written as the input. The one place the context
     * is built, for answering, evaluating and previewing alike.
     *
     * @param  list<string>  $shown
     * @return array{type: string, empty: bool, found: int, shown: int, byRef: array<string, array<string, mixed>>, productsByRef: array<string, array<string, mixed>>, analysis: array<string, mixed>, forModel: list<array<string, mixed>>, input: string, debug: array<string, mixed>|null}
     */
    private function prepare(string $shopId, string $question, array $shown, string $mode, ?string $viewing = null, bool $followUp = false): array
    {
        // A question about a product it names ("על מה הספר החטוף?"): that product, when the search before it
        // showed none of those it names; a question about what was shown stays about what was shown.
        $named = $viewing === null ? $this->namedProducts($question) : [];
        $shown = $named === [] || array_diff($named, $shown) === [] ? $shown : $named;
        // What the store's words and pages bring is then about those products, not about every one sharing a word.
        $this->namedTitles = $named === [] ? [] : CatalogProduct::query()->whereIn('external_id', $named)->pluck('title')->map(fn ($t): string => self::titleCore((string) $t))->filter()->values()->all();
        $floor = (float) Settings::get('assistant.site_min_similarity', $shopId);
        $found = array_values(array_filter(
            $this->passages->near($shopId, $question, ['product', 'content'], (int) Settings::get('assistant.site_passages', $shopId)),
            fn (array $p): bool => $p['similarity'] >= $floor,
        ));
        // The second look: what the question's other wordings find too, at the same floor, the nearest kept.
        foreach ($this->widenWith as $wording) {
            $found = self::merged($found, array_values(array_filter(
                $this->passages->near($shopId, $wording, ['product', 'content'], (int) Settings::get('assistant.site_passages', $shopId)),
                fn (array $p): bool => $p['similarity'] >= $floor,
            )), (int) Settings::get('assistant.site_passages', $shopId) + 4);
        }
        // Code before model: the pages whose title holds the question's words ("ביטול עסקה" for
        // "מה מדיניות הביטולים") are read first, whatever the meaning search found.
        $found = $this->pagesByWords($question, $found);
        // Before all: what the store says about itself on every product page, in its team's words,
        // and the team's answers to questions like this one.
        $found = $this->knowledge($question, $shopId, $found);
        // A product whose own page the question found by meaning ("מיקו דרים" for "לישון ממש טוב?") is one the answer may
        // show, before what the search showed: named in the answer without its card, it sent the shopper looking for it.
        if ($viewing === null && ! $followUp && $named === []) {
            $byText = array_map(fn (array $p): string => (string) $p['external_id'], array_filter($found, fn (array $p): bool => $p['source'] === 'product' && (string) ($p['external_id'] ?? '') !== ''));
            $shown = array_values(array_unique([...array_slice(array_values(array_unique($byText)), 0, self::FROM_PASSAGES), ...$shown]));
        }
        // "What is recommended most": the store's most popular, before the rest, and kept through the choice below. What
        // its customers buy, open and add to the cart, never a model's taste (as Aviad asked, 26.10).
        $popular = self::asksPopular($question) ? $this->popularFor($shopId, $question) : [];
        $popularTop = $viewing === null && ! $followUp ? array_slice(array_map('strval', array_keys($popular)), 0, (int) Settings::get('assistant.popular_products', $shopId)) : [];
        $shown = array_values(array_unique([...$popularTop, ...$shown]));
        $candidates = $this->withinPrice($shopId, $question, $this->candidates($shown));
        $type = AnswerContext::type($question);
        $counts = ['found' => count($found), 'shown' => count($candidates)];
        $debug = null;

        // Nothing at all is the same answer in every mode: decided before any choice is made.
        if ($found !== [] || $candidates !== []) {
            if ($mode === AnswerContext::DYNAMIC) {
                $selection = $this->context->select($shopId, $question, $type, $candidates, $found);
                [$candidates, $found, $debug] = [$selection['products'], $selection['passages'], $selection['debug']];
            }
        }
        $kept = array_map(fn (array $p): string => (string) ($p['external_id'] ?? ''), $candidates);
        $missing = array_values(array_diff($popularTop, $kept));
        if ($missing !== []) {
            $candidates = [...$candidates, ...$this->candidates($missing)];
        }

        $byRef = [];
        foreach ($found as $i => $passage) {
            $byRef['s'.($i + 1)] = $passage;
        }
        $productsByRef = [];
        foreach ($candidates as $i => $product) {
            $productsByRef['p'.($i + 1)] = $product;
        }

        // Code reads the results before the model: price order among what is in stock, what
        // is on sale, the brands, and whether the question asks for the cheapest or the dearest.
        // The model gets ranks, never prices: the page shows the current price.
        $analysis = self::analysis($question, $productsByRef, $popular);
        $forModel = $this->forModel($productsByRef, $analysis);

        if ($mode === AnswerContext::DYNAMIC) {
            $forModel = array_map(fn (array $entry): array => AnswerContext::fields($type, $entry, $productsByRef[$entry['ref']], $question), $forModel);
        }

        // The product whose page the question was asked from, by its ref.
        $viewingRef = $viewing === null ? null : array_search($viewing, array_map(fn (array $p): string => (string) ($p['external_id'] ?? ''), $productsByRef), true);

        $input = (string) json_encode([
            'question' => $question,
        ] + ($this->conversation === [] ? [] : ['conversation' => $this->conversation])
            + (is_string($viewingRef) ? ['viewing' => $viewingRef] : []) + [
                'products' => $forModel,
                'analysis' => $analysis,
                'passages' => array_map(fn (string $ref, array $p): array => ['ref' => $ref, 'title' => $p['title'], 'text' => $p['text']] + (isset($p['from']) ? ['from' => $p['from']] : []), array_keys($byRef), $byRef),
            ], JSON_UNESCAPED_UNICODE);

        return ['type' => $type, 'empty' => $counts['found'] === 0 && $counts['shown'] === 0] + $counts
            + compact('byRef', 'productsByRef', 'analysis', 'forModel', 'input', 'debug');
    }

    /**
     * @param  list<string>  $shown
     * @param  string|null  $mode  the shop's assistant.context_mode unless given
     * @param  bool  $remember  false for an evaluation: nothing is saved for the next shopper
     * @param  array<string, mixed>|null  $trace  filled with what was given and what came of it
     * @param  bool  $keep  false for a follow-up: the answer is about this conversation's products, never saved for the next shopper
     * @return array<string, mixed>
     */
    private function ask(string $shopId, string $question, string $locale, array $shown, ?string $mode = null, bool $remember = true, ?array &$trace = null, ?string $visitorHash = null, bool $keep = true, ?string $viewing = null, bool $forShopper = true): array
    {
        $result = $this->askOnce($shopId, $question, $locale, $shown, $mode, $remember, $trace, $visitorHash, $keep, $viewing, $forShopper);

        if (! $this->wantsSecondLook($shopId, $result)) {
            return $result;
        }

        // The second look: no answer from the question's own words, so it is asked in other ways
        // and the pages are searched with each; the writer and the checker of the other family are
        // asked once more on what that found. The first answer stands when this one finds none.
        $similar = $this->similarQuestions($shopId, $question, $locale, $forShopper);

        $again = null;

        if ($similar !== []) {
            [$this->widenWith, $this->scoped] = [$similar, true];

            try {
                $again = $this->askOnce($shopId, $question, $locale, $shown, $mode, $remember, $trace, $visitorHash, $keep, $viewing, $forShopper);
            } finally {
                [$this->widenWith, $this->scoped] = [[], false];
            }
        }

        if (in_array($again['outcome'] ?? null, [AssistantAnswer::ANSWERED, 'quoted'], true)) {
            return $again;
        }

        // Not found the wider way either: kept so, and not asked again until the site or its index changes.
        $kept = $remember && $keep ? self::siteAnswers()->where('question_key', Question::key($question))->where('outcome', '!=', AssistantAnswer::ANSWERED)->first() : null;
        $kept?->forceFill(['flags' => ['looked' => self::LOOK_VERSION] + (array) $kept->flags])->save();

        return $result;
    }

    /**
     * No answer from the pages the question's own words found, and the shop looks again (assistant.second_look).
     * Only when nothing was found: an answer code or the checker refused (a number the pages do not have)
     * is not looked for elsewhere.
     */
    private function wantsSecondLook(string $shopId, array $result): bool
    {
        return $this->frozen === null
            && in_array($result['outcome'] ?? null, [AssistantAnswer::NO_INFO, 'no_match'], true)
            && ($result['from'] ?? null) === 'model'
            && in_array($result['reason'] ?? 'not_found', ['not_found', 'no_answer', 'cites_nothing'], true)
            && Features::enabled('assistant.second_look', $shopId);
    }

    /**
     * The question asked in other ways, to search the pages with again. Code first: its parts, when it
     * asks more than one thing, and its topics as stores write them ("מה מדיניות ההחזרים?" is also
     * "מדיניות החזרות", TopicQuestions). Only when code has too few, up to three from a small model,
     * once a question: kept, never asked twice.
     *
     * @return list<string>
     */
    public function similarQuestions(string $shopId, string $question, string $locale, bool $forShopper = true): array
    {
        $similar = [...self::parts($question), ...$this->topicQuestions->questions($question, $locale)];

        if (count($similar) < self::ENOUGH_FROM_CODE) {
            $similar = [...$similar, ...$this->rephrased($shopId, $question, $locale, $forShopper)];
        }

        $out = [Question::key($question) => null];
        foreach ($similar as $line) {
            $line = trim((string) preg_replace('/\s+/u', ' ', (string) $line));
            $out[Question::key($line)] ??= $line === '' ? null : $line;
        }

        return array_slice(array_values(array_filter($out)), 0, self::MAX_SIMILAR);
    }

    /** @return list<string> the parts of a question that asks more than one thing, each of two words or more */
    public static function parts(string $question): array
    {
        $whole = trim($question, " \t?!.");
        $parts = array_map('trim', preg_split('/\s*(?:[?!;,]|\s(?:וגם|או|and|or)\s)\s*/u', $question) ?: []);

        return array_values(array_filter($parts, fn (string $part): bool => $part !== $whole && count(preg_split('/\s+/u', $part) ?: []) >= 2));
    }

    /** @return list<string> up to three other wordings from the small model, kept a month by the question and the prompt's version */
    private function rephrased(string $shopId, string $question, string $locale, bool $forShopper): array
    {
        $key = 'assistant:similar:'.$shopId.':v'.self::promptVersion('similar_questions').':'.Question::key($question);
        $kept = Cache::get($key);

        if (is_array($kept)) {
            return $kept;
        }

        $role = AgentModel::from('assistant.scope_provider', 'assistant.scope_model');
        $role = ['provider' => $role->provider, 'name' => $role->provider->value, 'model' => $role->model];
        $lines = null;

        $this->runs->track(
            agent: $forShopper ? self::AGENT : PrepareStoreAnswers::AGENT,
            action: self::SIMILAR_ACTION,
            shopId: $shopId,
            trigger: RunTrigger::Webhook,
            input: ['question' => $question],
            work: function (RunContext $run) use ($shopId, $question, $locale, $forShopper, $role, &$lines): void {
                try {
                    $this->spend->assertCanSpend(forShopper: $forShopper, estimatedUsd: (600 * $this->price('scope_input') + self::SIMILAR_OUTPUT_TOKENS * $this->price('scope_output')) / 1_000_000);
                    $reply = $this->call($run, $role, 'similar_questions', (string) json_encode([
                        'store' => $this->storeBrief($shopId),
                        'question' => $question,
                        'language' => $locale,
                    ], JSON_UNESCAPED_UNICODE), self::SIMILAR_OUTPUT_TOKENS, null, 'scope');
                } catch (SpendCapReached|ModelCallFailed $e) {
                    $run->fail('assistant::runs.similar_failed', [], $e->getMessage());

                    return;
                }

                $lines = self::checkedSimilar((array) ($reply->data['questions'] ?? []), $question);
                $run->output(['questions' => count($lines)])->summary('assistant::runs.similar_written', ['n' => (string) count($lines)]);
            },
        );

        if ($lines === null) {
            return [];
        }

        Cache::put($key, $lines, now()->addDays(30));

        return $lines;
    }

    /**
     * Code's gate on the model's wordings: at most three, each a short line, none with contact
     * details, a link or a price, none the question itself.
     *
     * @param  array<int|string, mixed>  $questions
     * @return list<string>
     */
    public static function checkedSimilar(array $questions, string $question): array
    {
        $out = [];

        foreach ($questions as $line) {
            $line = trim((string) preg_replace('/\s+/u', ' ', is_string($line) ? $line : ''));
            $length = mb_strlen($line);

            if ($length < 4 || $length > 160 || Question::hasContactDetails($line) || preg_match('~https?://|www\.|₪|ש"ח|ש״ח~u', $line) === 1 || Question::key($line) === Question::key($question)) {
                continue;
            }

            $out[Question::key($line)] = $line;
        }

        return array_slice(array_values($out), 0, 3);
    }

    /**
     * @param  list<string>  $shown
     * @param  array<string, mixed>|null  $trace
     * @return array<string, mixed>
     */
    private function askOnce(string $shopId, string $question, string $locale, array $shown, ?string $mode = null, bool $remember = true, ?array &$trace = null, ?string $visitorHash = null, bool $keep = true, ?string $viewing = null, bool $forShopper = true): array
    {
        $this->namedTitles = [];
        $result = $this->fixed('unavailable', $locale);
        $mode ??= (string) Settings::get('assistant.context_mode', $shopId);
        $corrected = null;

        $run = $this->runs->track(
            agent: $forShopper ? self::AGENT : PrepareStoreAnswers::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            trigger: $remember ? RunTrigger::Webhook : RunTrigger::Manual,
            input: ['question' => $question, 'products' => count($shown)] + ($remember ? [] : ['evaluation' => $mode]),
            work: function (RunContext $run) use ($shopId, $question, $locale, $shown, $mode, $remember, $visitorHash, $keep, $viewing, $forShopper, &$result, &$trace, &$corrected): void {
                $writer = AgentModel::from('assistant.answer_provider', 'assistant.answer_model');
                $writer = ['provider' => $writer->provider, 'name' => $writer->provider->value, 'model' => $writer->model];
                $checkerName = strtolower(trim((string) Settings::get('assistant.site_check_provider')));
                $checker = ['provider' => AiProviderName::tryFrom($checkerName), 'name' => $checkerName, 'model' => (string) Settings::get('assistant.site_check_model')];

                if ($checker['provider'] === null || $checker['provider']->family() === $writer['provider']->family()) {
                    $run->fail('assistant::runs.site_same_family', ['family' => $writer['provider']->label()]);

                    return;
                }

                $effort = (string) Settings::get('assistant.reasoning_effort');
                $effort = $effort === 'model_default' ? null : $effort;
                $replies = [];
                $flags = ['safe' => null, 'injection' => false, 'harmful' => false, 'personal' => false];

                // Whether the question is the store's to answer at all, and whether it asks for harm or
                // tries to instruct the assistant: a small model, before any page is read. A sensitive
                // subject the store deals in is the store's.
                if (Features::enabled('assistant.site_scope', $shopId) && ! $this->scoped) {
                    $scoper = AgentModel::from('assistant.scope_provider', 'assistant.scope_model');
                    $scoper = ['provider' => $scoper->provider, 'name' => $scoper->provider->value, 'model' => $scoper->model];

                    try {
                        $this->spend->assertCanSpend(forShopper: $forShopper, estimatedUsd: (800 * $this->price('scope_input') + self::SCOPE_OUTPUT_TOKENS * $this->price('scope_output')) / 1_000_000);
                        $scope = $this->call($run, $scoper, 'scope_site', (string) json_encode([
                            'store' => $this->storeBrief($shopId),
                            // what the store writes nearest the question (scope_site v7): its names and categories may say
                            // nothing of it ("לישון" in a store whose sleep formula is called "Dream")
                            'store_says' => $this->storeSays($shopId, $question),
                            'question' => $question,
                        ] + ($this->conversation === [] ? [] : ['conversation' => $this->conversation]) + [
                            'signals' => QuestionGuard::inspect($question)['signals'],
                        ], JSON_UNESCAPED_UNICODE), self::SCOPE_OUTPUT_TOKENS, $effort, 'scope');
                    } catch (SpendCapReached $e) {
                        $run->fail('assistant::runs.spend_cap', [], $e->getMessage());

                        return;
                    } catch (ModelCallFailed $e) {
                        $trace = ['failed' => $e->reason];
                        $run->fail('assistant::runs.model_failed', ['reason' => $e->reason], $e->getMessage());

                        return;
                    }

                    $replies[] = [$scope, 'scope'];
                    $scoped = [
                        'about_store' => ($scope->data['about_store'] ?? null) === true,
                        'harmful' => ($scope->data['harmful'] ?? false) === true,
                        'injection' => ($scope->data['injection'] ?? false) === true,
                    ];
                    $flags['harmful'] = $scoped['harmful'];
                    $flags['injection'] = $scoped['injection'];

                    if ($scoped['harmful'] || $scoped['injection']) {
                        // A rule broken: counted against the visitor, and nothing is saved, so the next
                        // asker is checked again.
                        if ($remember && $visitorHash !== null && ! $this->trial) {
                            Offenders::record($shopId, $visitorHash, Barrier::ip());
                        }
                        $reason = $scoped['harmful'] ? 'harmful' : 'injection';
                        $result = $this->fixed('out_of_scope', $locale, 'model') + ['reason' => $reason];
                        $trace = ['scope' => $scoped, 'refused' => $reason];
                        $run->output(['outcome' => AssistantAnswer::OUT_OF_SCOPE, 'refused' => $reason, 'scope' => $scoped])->summary('assistant::runs.out_of_scope');

                        return;
                    }

                    // The question as the shopper meant to type it (scope_site v6): read again for a set
                    // ("איזזה ספרי חטופים" asks for one) and searched with. The words the shopper typed
                    // stay theirs: the answer is saved and logged under them.
                    $corrected = self::corrected($question, $scope->data['corrected'] ?? null);
                    // a reply, as it stands alone (scope_site v8): "כמה פעמים כדאי לקחת את הקורדיספס?", what is searched and answered
                    $corrected = $this->conversation === [] ? $corrected : (self::standalone($scope->data['standalone'] ?? null) ?? $corrected);
                    if ($corrected !== null && $remember && $keep && $viewing === null && $visitorHash !== null
                        && ($set = $this->set($shopId, $corrected, $visitorHash, $locale)) !== null) {
                        $result = $set;
                        $run->output(['outcome' => $set['outcome'], 'corrected' => $corrected, 'set' => count((array) ($set['set']['ids'] ?? []))])->summary('assistant::runs.site_corrected_set');

                        return;
                    }

                    // Asked about given products (from a product's page, or the turn before): they are the
                    // store's, whatever the words alone seem to be about ("מה מיוחד במוצר הזה?").
                    if (! $scoped['about_store'] && $keep) {
                        if ($remember) {
                            $this->save($question, AssistantAnswer::OUT_OF_SCOPE, null, [], [], $scoper['model'], $replies, $run, $flags);
                        }
                        $result = $this->fixed('out_of_scope', $locale, 'model') + ['reason' => 'not_about_store'];
                        $trace = ['scope' => $scoped, 'refused' => 'not_about_store'];
                        $run->output(['outcome' => AssistantAnswer::OUT_OF_SCOPE, 'refused' => 'not_about_store', 'scope' => $scoped])->summary('assistant::runs.out_of_scope');

                        return;
                    }
                }

                // a reply the topic check did not write to stand alone (no check in this store): searched with the question
                // before it, the nearest it can be read without a model
                if ($this->conversation !== [] && $corrected === null) {
                    $corrected = $this->conversation[count($this->conversation) - 1]['question'].' '.$question;
                }
                // A replay on frozen data gives the writer what it was given then; everything else, today's.
                $prepared = $this->frozen ?? $this->prepare($shopId, $corrected ?? $question, $shown, $mode, $viewing, $this->onShown);
                ['byRef' => $byRef, 'productsByRef' => $productsByRef, 'analysis' => $analysis, 'forModel' => $forModel, 'input' => $input] = $prepared;
                $context = ['mode' => $mode, 'type' => $prepared['type'], 'constraints' => AnswerContext::constraints($question), 'products_shown' => $prepared['shown'], 'products_sent' => count($productsByRef),
                    'passages_found' => $prepared['found'], 'passages_sent' => count($byRef), 'chars' => mb_strlen($input)];
                $trace = ['context' => $context, 'debug' => $prepared['debug']];
                $noAnswer = $shown === [] ? AssistantAnswer::NO_INFO : 'no_match';
                // what the writer is given, for the check screen: the pages by name and kind, the products by name
                if ($this->trial) {
                    $this->looked[] = [
                        'question' => $corrected ?? $question,
                        'pages' => array_values(array_map(fn (array $p): array => array_filter(['title' => (string) ($p['title'] ?? ''), 'source' => $p['source'] ?? null, 'kind' => $p['kind'] ?? null, 'from' => $p['from'] ?? null]), $byRef)),
                        'products' => array_values(array_map(fn (array $p): string => (string) ($p['title'] ?? ''), $productsByRef)),
                        'chars' => mb_strlen($input),
                    ];
                }

                if ($prepared['empty']) {
                    if ($remember && $keep) {
                        $this->save($question, AssistantAnswer::NO_INFO, null, [], [], null, [], $run);
                    }
                    $result = $this->fixed($noAnswer, $locale, 'model');
                    $run->output(['outcome' => AssistantAnswer::NO_INFO, 'passages' => 0, 'products' => 0, 'context' => $context])->summary('assistant::runs.site_nothing_near');

                    return;
                }

                $maxOutput = (int) Settings::get('assistant.answer_max_output_tokens');
                $checkOutput = (int) Settings::get('assistant.site_check_max_output_tokens');
                // What the checker did with this answer, kept on the run: how often it changes an answer,
                // for which kind of question, at what cost and how fast. Read by assistant:checker-report.
                $checked = ['ran' => false];

                $wrote = [];

                try {
                    $estimated = (int) ceil(mb_strlen($input) / 2) + 600;
                    $this->spend->assertCanSpend(forShopper: $forShopper, estimatedUsd: (
                        $estimated * $this->price('answer_input') + $maxOutput * $this->price('answer_output')
                        + ($estimated + 400) * $this->price('site_check_input') + $checkOutput * $this->price('site_check_output')
                    ) / 1_000_000);

                    $started = hrtime(true);
                    $reply = $this->call($run, $writer, 'answer_site', $input, $maxOutput, $effort, 'answer');
                    $replies[] = [$reply, 'answer'];
                    // Token counts as "in" and "out": a key holding "token" is redacted on a run.
                    $wrote = ['model' => $writer['model'], 'ms' => (int) round((hrtime(true) - $started) / 1e6), 'in' => $reply->inputTokens, 'out' => $reply->outputTokens,
                        'cost_usd' => round($reply->costUsd($this->price('answer_input'), $this->price('answer_output')), 6)];

                    $screened = $this->screen($reply->data, $prepared, $question, $mode);
                    ['answer' => $answer, 'cited' => $cited, 'picks' => $picks, 'refusal' => $answerRefusal] = $screened;
                    // Every product the answer names is shown under it, as Aviad asked (2026-10-21): "דרקולה" in a sentence
                    // is a card too. Only of the products the writer was given, and before the checker, which judges them
                    // like any pick: nothing reaches the shopper unchecked.
                    if ($answer !== '') {
                        // a product whose own page the answer cites is one it shows, given to the writer or not (Sela,
                        // 26.10: "12 כללים לחיים" recommended from its page, left out of the products: no card)
                        $prepared['productsByRef'] = $this->withCited($question, $cited, $byRef, $prepared['productsByRef']);
                        $picks = self::namedInAnswer($answer, $picks, $prepared['productsByRef']);
                        // and every product whose own page it rests on: "מתוך: פטריית קורדיספס" under an answer about
                        // cordyceps, with no card to buy it from (Mycolivia, 25.10)
                        $picks = self::citedProducts($cited, $byRef, $picks, $prepared['productsByRef']);
                    }
                    // Sentences code took out (a number the pages do not have), the rest kept.
                    $checked['trimmed'] = $screened['trimmed'];
                    $flags['personal'] = $answerRefusal === 'contact_details';

                    $checked['type'] = self::questionType($analysis['asks'] ?? null, $cited, $picks);

                    if (($reply->data['found'] ?? null) !== true || ($answer === '' && $picks === [])) {
                        $refusal = $answerRefusal ?? 'not_found';
                        $checked['skipped'] = $refusal;
                        // Not found on the site: the shopper is told what was not found, in their words, and that the team
                        // can help (code checks it says nothing more). An offensive question, or one that instructs, gets nothing.
                        $unfit = ($reply->data['fine'] ?? true) === false;
                        // a writer that found nothing writes no answer, which code reads as "no answer" or "cites
                        // nothing": its line is the shopper's all the same ("אין באתר המלצה אחת…")
                        $notFound = in_array($refusal, ['not_found', 'no_answer', 'cites_nothing'], true) && ! $unfit
                            ? self::notFoundLine((string) ($reply->data['not_found'] ?? ''), $question) : null;
                    } else {
                        $started = hrtime(true);

                        try {
                            $check = $this->call($run, $checker, 'verify_site', $this->checkInput($this->conversation === [] ? $question : ($corrected ?? $question), $answer, $cited, $picks, $prepared), $checkOutput, null, 'site_check');
                        } catch (ModelCallFailed $e) {
                            // The check could not be made (its provider refused, a key, an outage): the writer's
                            // words are never shown unchecked. What the store's own pages say, quoted as they
                            // are with where, is shown instead, said to be unchecked; nothing is kept or shared.
                            $quotes = self::quotes($question, array_map(fn (string $ref): array => $byRef[$ref], $cited), fn (array $passages): array => $this->sources($passages));
                            $trace += ['writer' => $wrote, 'check' => $checked + ['failed' => $e->reason], 'quoted' => count($quotes)];
                            $result = $quotes === [] ? $this->fixed('unavailable', $locale) : ['outcome' => 'quoted', 'answer' => '', 'from' => 'page', 'quotes' => $quotes];
                            $run->output(['outcome' => $quotes === [] ? 'unavailable' : 'quoted', 'check_failed' => $e->reason, 'error' => mb_substr($e->getMessage(), 0, 300), 'quotes' => count($quotes),
                                'checker' => $checker['name'].' · '.$checker['model'], 'writer' => $wrote, 'context' => $trace['context']])
                                ->summary('assistant::runs.site_unchecked', ['reason' => $e->reason]);

                            return;
                        }
                        $replies[] = [$check, 'site_check'];
                        $checked += [
                            'model' => $checker['model'],
                            'ms' => (int) round((hrtime(true) - $started) / 1e6),
                            // Token counts as "in" and "out": a key holding "token" is redacted on a run.
                            'in' => $check->inputTokens,
                            'out' => $check->outputTokens,
                            'cost_usd' => round($check->costUsd($this->price('site_check_input'), $this->price('site_check_output')), 6),
                        ];
                        $checked['ran'] = true;

                        $applied = self::applyCheck($check->data, $answer, $picks);
                        ['answer' => $answer, 'picks' => $picks, 'refusal' => $refusal] = $applied;
                        $checked['verdict'] = $applied['verdict'];
                        // Why, for the record: the decision and each issue's kind and severity (never the shopper's words).
                        $checked['decision'] = $applied['decision'] ?? null;
                        $checked['issues'] = array_map(fn (array $i): string => $i['severity'].':'.$i['kind'], $applied['issues'] ?? []);
                        // The checker's focused fix passes code's checks too, once: no loop, no new model call.
                        if (($applied['revised'] ?? false) && $answer !== '') {
                            [$answer, $fixRefusal] = self::gate($answer, $screened['known'], $screened['pages']);
                            $refusal = $answer === '' && $picks === [] ? ($fixRefusal ?? 'check_unfixed') : null;
                        }
                        // An attempt to instruct the assistant counts against the visitor.
                        $flags['safe'] = $applied['safe'];
                        $flags['injection'] = $flags['injection'] || $applied['injection'];
                        if ($flags['injection'] && $remember && $visitorHash !== null && ! $this->trial) {
                            Offenders::record($shopId, $visitorHash, Barrier::ip());
                        }
                    }
                } catch (SpendCapReached $e) {
                    $run->fail('assistant::runs.spend_cap', [], $e->getMessage());

                    return;
                } catch (ModelCallFailed $e) {
                    $trace += ['failed' => $e->reason, 'writer' => $wrote, 'check' => $checked];
                    $run->fail('assistant::runs.model_failed', ['reason' => $e->reason], $e->getMessage());

                    return;
                }

                // never a sentence cut in its middle ("local travel professio", Gil Travel, 28.10): back to its last whole one
                $answer = self::endsWhole($answer);
                $sources = $refusal === null ? $this->sources(array_map(fn (string $ref): array => $byRef[$ref], $cited)) : [];
                // what it recommends by name that is none of its products ("שיטאקה"): only names the answer, as checked, still says
                $names = $refusal === null ? self::names($reply->data['names'] ?? null, $answer) : [];
                $flags = $names === [] ? $flags : (array) $flags + ['names' => $names];
                $stored = array_map(fn (array $pick): array => ['external_id' => $pick['external_id'], 'title' => $pick['title'], 'why' => $pick['why']], $picks);
                $unfit ??= false;
                $notFound ??= null;
                $outcome = $refusal === null ? AssistantAnswer::ANSWERED : ($unfit ? AssistantAnswer::OUT_OF_SCOPE : AssistantAnswer::NO_INFO);
                // A "not found" is kept with its line: the next shopper who asks the same is told it without a model.
                // the question as the first model read it, its slips mended ("יש מוצרים בכסף??"): how it is shown to others
                $saved = $remember && $keep ? $this->save($question, $outcome, $refusal === null ? $answer : $notFound, $sources, $stored, $writer['model'], $replies, $run, $corrected === null ? $flags : (array) $flags + ['corrected' => $corrected]) : null;

                // This visitor got it clean: checked, safe, no attempt to instruct, nothing personal,
                // nothing harmful. Counted once; enough different visitors share it with everyone.
                if ($saved !== null && $refusal === null && $visitorHash !== null && $flags['safe'] === true && ! $flags['injection'] && ! $flags['harmful'] && ! $flags['personal']) {
                    $saved->countVisitor($visitorHash, (int) Settings::get('assistant.share_after_visitors', $shopId));
                }

                $result = $refusal === null
                    ? ['outcome' => $outcome, 'answer' => $answer, 'from' => 'model', 'sources' => $sources, 'picks' => $this->present($stored), 'answer_id' => $saved?->id] + $this->describedFor($names, $stored)
                    : ($unfit ? $this->fixed('out_of_scope', $locale, 'model') + ['reason' => 'unfit']
                        : $this->fixed($noAnswer, $locale, 'model') + ['answer_id' => $saved?->id, 'reason' => mb_substr((string) $refusal, 0, 40)] + ($notFound === null ? [] : ['not_found' => $notFound]));
                $trace += ['writer' => $wrote, 'check' => $checked, 'refused' => $refusal, 'picks' => array_column($stored, 'external_id')];
                $run->output(['outcome' => $outcome, 'refused' => $refusal, 'cited' => count($cited), 'picks' => count($picks), 'answer' => $answer, 'checker' => $checker['name'].' · '.$checker['model'],
                    'check' => $checked, 'context' => $trace['context'], 'writer' => $wrote])
                    ->summary('assistant::runs.site_'.$outcome);
            },
        );

        // How this question was answered, kept with its ask (logged()): the run, the prompt, the models,
        // the checker's verdict, what was sent, the cost and the time. Counts and ids only, no text.
        $trace ??= [];
        $this->answered = [
            'run_id' => $run->id,
            'prompt_version' => $this->promptVersions['answer_site'] ?? self::promptVersion('answer_site'),
            'writer_model' => isset($trace['writer']['model']) ? mb_substr((string) $trace['writer']['model'], 0, 80) : null,
            'checker_model' => isset($trace['check']['model']) ? mb_substr((string) $trace['check']['model'], 0, 80) : null,
            'verdict' => isset($trace['check']['verdict']) ? (string) $trace['check']['verdict'] : (isset($trace['check']['skipped']) ? 'skipped:'.mb_substr((string) $trace['check']['skipped'], 0, 30) : null),
            'trace' => array_filter([
                'context' => isset($trace['context']) ? array_intersect_key((array) $trace['context'], array_flip(['mode', 'type', 'products_shown', 'products_sent', 'passages_found', 'passages_sent', 'chars'])) : null,
                'refused' => $trace['refused'] ?? null,
                'failed' => $trace['failed'] ?? null,
                'scope' => $trace['scope'] ?? null,
                'writer_ms' => $trace['writer']['ms'] ?? null,
                'check_ms' => $trace['check']['ms'] ?? null,
                'picks' => $trace['picks'] ?? null,
                'corrected' => $corrected,
            ], fn ($v): bool => $v !== null && $v !== []),
            'cost_usd' => $run->cost_usd === null ? null : (float) $run->cost_usd,
            'duration_ms' => $run->duration_ms,
        ];

        // the shopper sees how the question was read, when it was read other than typed
        // never for a reply: it is shown as the shopper wrote it, in the conversation
        return $corrected !== null && $this->conversation === [] && $result['outcome'] === AssistantAnswer::ANSWERED ? $result + ['corrected' => $corrected] : $result;
    }

    /**
     * The scope check's wording of the question, kept only when it fixes the question rather than
     * changes it: not empty, not much longer, and mostly the same letters as typed. Null when it is the
     * question as typed, or not to be trusted.
     */
    public static function corrected(string $question, mixed $corrected): ?string
    {
        $corrected = trim((string) preg_replace('/\s+/u', ' ', strip_tags(is_string($corrected) ? $corrected : '')));
        $typed = Question::normalize($question);
        $fixed = Question::normalize($corrected);

        if ($fixed === '' || $fixed === $typed || mb_strlen($corrected) > mb_strlen($question) * 1.5 + 10) {
            return null;
        }

        similar_text($typed, $fixed, $percent);

        return $percent >= self::CORRECTED_MIN_SAME ? mb_substr($corrected, 0, 500) : null;
    }

    /**
     * A question asked again as a shopper asked it, with the writer's prompt at the version the operator
     * chose (null: the current one), on what the writer was given then ($frozen, a snapshot()) or on
     * today's catalogue and pages: the same pipeline as evaluate(), nothing saved, the run recorded as
     * an evaluation with its real cost. Returns what came of it and how: outcome, answer, picks, verdict,
     * prompt version, cost and time.
     *
     * @param  list<string>  $products  external ids shown, best first
     * @return array{result: array<string, mixed>, trace: array<string, mixed>, answered: array<string, mixed>|null}
     */
    public function replay(string $shopId, string $question, array $products, ?int $answerVersion = null, string $locale = 'he', ?array $frozen = null): array
    {
        if ($answerVersion !== null && ! in_array($answerVersion, self::promptVersions('answer_site'), true)) {
            throw new \InvalidArgumentException("answer_site v{$answerVersion} does not exist.");
        }

        $this->promptVersions = $answerVersion === null ? [] : ['answer_site' => $answerVersion];
        $this->answered = null;
        $this->frozen = $frozen === null ? null : array_diff_key($frozen, array_flip(['mode', 'question']));

        try {
            $out = $this->evaluate($shopId, $question, $products, (string) ($frozen['mode'] ?? Settings::get('assistant.context_mode', $shopId)), $locale);

            return $out + ['answered' => $this->answered];
        } finally {
            $this->promptVersions = [];
            $this->answered = null;
            $this->frozen = null;
        }
    }

    /** @return list<int> the writer prompt's versions a replay may ask with, newest first (the bench's v5 aside) */
    public static function promptVersions(string $name): array
    {
        $versions = [];

        foreach (glob(__DIR__.'/../Prompts/'.$name.'.v*.md') ?: [] as $file) {
            if (preg_match('/\.v(\d+)\.md$/', $file, $m) === 1 && (int) $m[1] !== 5) {
                $versions[] = (int) $m[1];
            }
        }

        rsort($versions);

        return $versions;
    }

    /** The version of a prompt prompt() reads: the newest file there is, the bench's v5 aside. */
    /**
     * The conversation a reply carries, as the storefront sent it: the last MAX_THREAD questions with their answers,
     * each cut short, plain text; a turn without both is left out.
     *
     * @param  array<mixed>  $thread
     * @return list<array{question: string, answer: string}>
     */
    /**
     * The names a writer gave for what it recommends that is none of its products, kept only when the answer says them:
     * a few words each, at most MAX_NAMES.
     *
     * @return list<string>
     */
    public static function names(mixed $names, string $answer): array
    {
        $said = ' '.Question::normalize($answer).' ';
        $out = [];
        foreach (is_array($names) ? $names : [] as $name) {
            $name = is_string($name) ? trim(strip_tags($name)) : '';
            $key = Question::normalize($name);
            if ($key !== '' && mb_strlen($name) <= 40 && count(explode(' ', $key)) <= 3 && str_contains($said, $key) && ! in_array($name, $out, true)) {
                $out[] = $name;
            }
        }

        return array_slice($out, 0, self::MAX_NAMES);
    }

    /**
     * The products whose own descriptions say what an answer recommends by name ("שיטאקה": the formulas that list it),
     * besides its picks, for the shopper to see with it. Read in code (ProductsDescribed), never a model.
     *
     * @param  list<string>  $names
     * @param  list<array<string, mixed>>  $picks
     * @return array{named?: array{names: list<string>, ids: list<string>}}
     */
    private function describedFor(array $names, array $picks): array
    {
        if ($names === [] || ! app()->bound(ProductsDescribed::class)) {
            return [];
        }
        $shopId = $this->tenant->require();
        $picked = array_flip(array_map(fn (array $p): string => (string) ($p['external_id'] ?? ''), $picks));
        $ids = [];
        foreach ($names as $name) {
            foreach (app(ProductsDescribed::class)->saying($shopId, (string) $name) as $id) {
                if (! isset($picked[$id]) && ! in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
            }
        }

        return $ids === [] ? [] : ['named' => ['names' => array_values($names), 'ids' => array_slice($ids, 0, self::NAMED_SHOWN)]];
    }

    /** A reply written to stand alone by the topic check, plain and short; null when it gave none. */
    public static function standalone(mixed $text): ?string
    {
        $text = is_string($text) ? trim((string) preg_replace('/\s+/u', ' ', strip_tags($text))) : '';

        return $text === '' || mb_strlen($text) > self::THREAD_QUESTION_CHARS ? null : $text;
    }

    public static function thread(array $thread): array
    {
        $out = [];
        foreach (array_slice(array_values($thread), -self::MAX_THREAD) as $turn) {
            $q = is_array($turn) && is_string($turn['q'] ?? null) ? trim(strip_tags($turn['q'])) : '';
            $a = is_array($turn) && is_string($turn['a'] ?? null) ? trim(strip_tags($turn['a'])) : '';
            if ($q !== '' && $a !== '') {
                $out[] = ['question' => mb_substr($q, 0, self::THREAD_QUESTION_CHARS), 'answer' => mb_substr($a, 0, self::THREAD_ANSWER_CHARS)];
            }
        }

        return $out;
    }

    public static function promptVersion(string $name): ?int
    {
        for ($version = self::PROMPT_VERSION; $version > 0; $version--) {
            if ($version !== 5 && is_file(__DIR__.'/../Prompts/'.$name.'.v'.$version.'.md')) {
                return $version;
            }
        }

        return null;
    }

    /**
     * What the writer is given for a question, built now as the search box would build it (the
     * same prepare(), the question's stored vector): for the writer benchmark. No model is called.
     *
     * @param  list<string>  $products  external ids shown, best first
     * @return array<string, mixed> prepare()'s output with the mode and the cleaned question
     */
    public function snapshot(string $shopId, string $question, array $products, ?string $mode = null): array
    {
        $question = Question::clean($question, (int) Settings::get('assistant.max_question_chars', $shopId));
        $products = array_slice(array_values(array_unique(array_map('strval', $products))), 0, self::MAX_PRODUCTS);

        return $this->tenant->run($shopId, function () use ($shopId, $question, $products, $mode): array {
            $mode ??= (string) Settings::get('assistant.context_mode', $shopId);

            return ['mode' => $mode, 'question' => $question] + $this->prepare($shopId, $question, $products, $mode);
        });
    }

    /**
     * Code's gate on a writer's reply: the answer unless code refuses it (empty, citing nothing,
     * contact details, a price, a number the pages do not have), the passages it may cite, and the
     * picks it may make. The search box and the writer benchmark pass every writer through it.
     *
     * @param  array<string, mixed>  $data  the writer's JSON
     * @param  array{byRef: array<string, array<string, mixed>>, productsByRef: array<string, array<string, mixed>>, forModel: list<array<string, mixed>>}  $prepared
     * @return array{answer: string, cited: list<string>, picks: list<array{ref: string, external_id: string, title: string, why: string}>, refusal: string|null}
     */
    public function screen(array $data, array $prepared, string $question, string $mode): array
    {
        ['byRef' => $byRef, 'productsByRef' => $productsByRef, 'forModel' => $forModel] = $prepared;
        $answer = self::withoutRefs(self::withoutMarkdown(mb_substr(trim((string) ($data['answer'] ?? '')), 0, self::MAX_ANSWER_CHARS)));
        // a ref as a writer gives it: "s1", or now and then {"ref": "s1"}
        $cited = array_values(array_unique(array_filter(array_map(fn ($ref): string => is_array($ref) ? (string) ($ref['ref'] ?? $ref['id'] ?? '') : (string) $ref, (array) ($data['refs'] ?? [])), fn (string $ref): bool => isset($byRef[$ref]))));
        $picks = $this->picks((array) ($data['picks'] ?? []), $productsByRef);
        // About the product the question named, from its own description, citing nothing: that product is what it rests on.
        if ($cited === [] && $picks === [] && $this->namedTitles !== [] && isset($productsByRef['p1']) && trim((string) ($data['answer'] ?? '')) !== '') {
            $picks = $this->picks([['ref' => 'p1', 'why' => '']], $productsByRef);
        }
        // An answer that names the products it compares rests on their own descriptions, as picks do: "מה ההבדל ביניהם?"
        // about the books on screen cites no page (Sela, 25.10). Each named product goes to the checker like any pick.
        if ($cited === [] && $picks === [] && $answer !== '') {
            $picks = self::namedInAnswer($answer, [], $productsByRef);
        }
        // A number the answer gives is known when any page the writer was given says it, cited or not (Rocks, 25.10: "14
        // יום" was in the line every product page prints and in the returns policy, the writer cited the two pages that
        // say "שבועיים", and the sentence that answered the question was taken out).
        $known = implode("\n", array_map(fn (array $passage): string => $passage['title']."\n".$passage['text'], $byRef))
            ."\n".implode("\n", array_map(fn (array $p): string => $p['title'].' '.$p['about'], $productsByRef))."\n".$question;

        // A size or a measure the model was given in dynamic mode is in what it was given.
        if ($mode === AnswerContext::DYNAMIC) {
            $known .= "\n".json_encode($forModel, JSON_UNESCAPED_UNICODE);
        }

        // The products' prices as the writer was given them (forModel()): a sum in the answer is one of these, or it goes.
        $prices = implode("\n", array_map(fn (array $p): string => implode(' ', [$p['price'] ?? '', ...(array) ($p['option_prices'] ?? [])]), $forModel));
        $known .= "\n".$prices;

        // What the cited pages themselves say: a store's policy may name its cancellation fee ("עד 5% או
        // 100 ₪") and how to reach it; quoted from there, that is the store's word, not one made up. And the prices above.
        $pages = implode("\n", array_map(fn (string $ref): string => $byRef[$ref]['title']."\n".$byRef[$ref]['text'], $cited))."\n".$prices;

        // Code's checks on the answer, sentence by sentence (gate()); the picks stand or fall on their own.
        $trimmed = 0;
        $refusal = match (true) {
            $answer === '' => 'no_answer',
            $cited === [] && $picks === [] => 'cites_nothing',
            default => null,
        };
        if ($refusal === null) {
            [$answer, $refusal, $trimmed] = self::gate($answer, $known, $pages);
        }

        return ['answer' => $refusal === null ? $answer : '', 'cited' => $cited, 'picks' => $picks, 'refusal' => $refusal, 'known' => $known, 'pages' => $pages, 'trimmed' => $trimmed];
    }

    /** The longest "not found" line shown: a sentence or two, never an answer. */
    private const NOT_FOUND_CHARS = 240;

    /**
     * The writer's "not found" line for the shopper, or null: short, no number the question does not
     * hold, no price, contact or link (gate()), so it can say what was not found and nothing more.
     */
    public static function notFoundLine(string $line, string $question): ?string
    {
        $line = trim((string) preg_replace('/\s+/u', ' ', $line));

        if ($line === '' || mb_strlen($line) > self::NOT_FOUND_CHARS || preg_match('~https?://|www\.~i', $line) === 1) {
            return null;
        }
        // The writer is never given prices (code reads them), so whatever it says of prices is not known to it: "האתר לא
        // מציג כאן מחירים" told a shopper the store has none (Rocks, 25.10). The plain "not found" is said instead.
        if (preg_match('/מחיר|תקציב|₪|ש["״]ח|שקל|\b(?:price|prices|priced|budget|cost)\b/iu', $line) === 1) {
            return null;
        }

        [$left, $refusal] = self::gate($line, $question, '');

        return $refusal === null && $left === $line ? $line : null;
    }

    /** Whether an answer says what was asked about is not here (SAYS_MISSING). */
    public static function saysMissing(string $answer): bool
    {
        return preg_match(self::SAYS_MISSING, $answer) === 1;
    }

    /**
     * Code's checks on an answer's text, a sentence at a time, before and after any model's check: a
     * sentence with contact details or a price the cited pages do not write, an internal ref, a number
     * the pages do not have, or a measure in another unit than theirs (0.72 carats where the pages say
     * 0.72 grams) is taken out, and the rest stands. A fix, not a new answer: when less than
     * KEPT_SHARE of it, or nothing worth showing, would be left, the whole is refused as before.
     *
     * @return array{0: string, 1: string|null, 2: int} what is left, the refusal when nothing is, sentences taken out
     */
    public static function gate(string $answer, string $known, string $pages): array
    {
        $fault = fn (string $text): ?string => match (true) {
            (Question::hasContactDetails($text) || array_diff(Privacy::redact($text)['found'], ['link']) !== []) && ! self::contactsFrom($text, $pages) => 'contact_details',
            preg_match('~₪|ש"ח|ש״ח|\d+\s*שקל~u', $text) === 1 && ! self::amountsFrom($text, $pages) => 'price',
            self::hasRef($text) => 'internal_ref',
            self::hasNumberNotIn($text, $known) => 'number_not_in_the_pages',
            self::hasMeasureNotIn($text, $known) => 'measure_not_in_the_pages',
            // something still to come on a day already gone: a stale banner told as news
            Dated::past($text) => 'past_date',
            default => null,
        };

        $whole = $fault($answer);
        if ($whole === null) {
            return [$answer, null, 0];
        }

        [$left, $out] = self::withoutSentences($answer, fn (string $sentence): bool => $fault($sentence) !== null);

        return $left === '' ? ['', $whole, $out] : [$left, null, $out];
    }

    /**
     * The answer without its sentences that $bad marks, the sentences and line breaks around what stays
     * kept as they were; empty when too little is left to be worth showing (under 20 characters, or under
     * KEPT_SHARE of the answer).
     *
     * @param  callable(string): bool  $bad
     * @return array{0: string, 1: int} what is left, and how many sentences went
     */
    public static function withoutSentences(string $answer, callable $bad): array
    {
        $parts = preg_split('/(\n+|(?<=[.!?])\s+)/u', $answer, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$answer];
        $left = '';
        $out = 0;
        for ($i = 0; $i < count($parts); $i += 2) {
            if (trim($parts[$i]) !== '' && $bad($parts[$i])) {
                $out++;
                // a bare "כן." or "Yes." just before it answered what went: it goes too (Rocks, 25.10: "כן." was left
                // in front of the exceptions, the policy it said yes to taken out)
                $left = (string) preg_replace('/(?:^|(?<=[.!?\n]))\s*(?:כן|לא|בהחלט|yes|no|sure)[.!,]?\s*$/iu', '', $left);

                continue;
            }
            $left .= $parts[$i].($parts[$i + 1] ?? '');
        }
        $left = trim($left);

        return [$left === '' || mb_strlen($left) < 20 || mb_strlen($left) < mb_strlen($answer) * self::KEPT_SHARE ? '' : $left, $out];
    }

    /**
     * Whether the answer gives a measure (a number with its unit) that the pages do not give in the same
     * unit: "0.72 קראט" where they say "0.72 גרם", or "30 ס\"מ" where they say "30 מ\"מ". A number alone is
     * hasNumberNotIn's. Like with like only: carats, grams, kilos, centimetres, millimetres, metres,
     * litres, millilitres, percent, pages.
     */
    public static function hasMeasureNotIn(string $answer, string $known): bool
    {
        $units = [
            'carat' => 'קראט|קרט|ct|k(?![a-z])',
            'gram' => 'גרם|גר[\'׳]|gr?(?![a-z])',
            'kilo' => 'ק"ג|ק״ג|קילוגרם|קילו|kg',
            'cm' => 'ס"מ|ס״מ|סנטימטר(?:ים)?|cm',
            'mm' => 'מ"מ|מ״מ|מילימטר(?:ים)?|mm',
            'metre' => 'מטר(?:ים)?|m(?![a-z])',
            'litre' => 'ליטר(?:ים)?|l(?![a-z])',
            'ml' => 'מ"ל|מ״ל|ml',
            'percent' => '%|אחוז(?:ים)?',
            'pages' => 'עמודים|עמ[\'׳]',
        ];
        $plain = fn (string $text): string => str_replace(',', '.', mb_strtolower($text));
        $known = $plain($known);

        foreach ($units as $family => $pattern) {
            preg_match_all('/(\d+(?:\.\d+)?)\s?(?:'.$pattern.')/u', $plain($answer), $found);
            foreach (array_unique($found[1]) as $number) {
                if (preg_match('/(?<![\d.])'.preg_quote($number, '/').'\s?(?:'.$pattern.')/u', $known) !== 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The refs the writer was given ("p5", "s2") are its own, never the shopper's: one that only
     * names a product beside its name, in brackets or after it, is dropped; one left anywhere else
     * refuses the text, since what it stands for is unknown to the shopper.
     */
    public static function withoutRefs(string $text): string
    {
        // "(p5)", "(p5, p6)", "[s2]", "(ref p5)", " - p5" at the end of a clause.
        $text = (string) preg_replace('~\s*[(\[]\s*(?:ref\s*)?[ps]\d{1,2}(?:\s*[,/]\s*[ps]\d{1,2})*\s*[)\]]~iu', '', $text);
        // Only a dash or colon that stands apart (" - p5"): "מ-p3" is a prefix glued to the ref, a ref used as a word.
        $text = (string) preg_replace('~(?<=\s)[-–—:]\s*[ps]\d{1,2}(?=[\s.,;!?]|$)~iu', '', $text);

        return trim((string) preg_replace('/[ \t]{2,}/u', ' ', $text));
    }

    /** A writer's bold and italics as plain words: "**חיזוק הגוף**" showed its stars to the shopper (Mycolivia, 25.10). */
    public static function withoutMarkdown(string $text): string
    {
        $text = (string) preg_replace('/(\*\*|__)(?=\S)(.+?)(?<=\S)\1/us', '$2', $text);

        return str_replace('**', '', $text);
    }

    /** Whether a text still shows an internal ref ("p5", "s2") to the shopper. */
    public static function hasRef(string $text): bool
    {
        return preg_match('~(?<![A-Za-z0-9])[ps]\d{1,2}(?![A-Za-z0-9])~u', $text) === 1;
    }

    /**
     * What the checker reads: the question, the answer, the passages it cites, and each pick with
     * what code measured about it.
     *
     * @param  list<string>  $cited
     * @param  list<array{ref: string, title: string, why: string}>  $picks
     * @param  array{byRef: array<string, array<string, mixed>>, productsByRef: array<string, array<string, mixed>>, analysis: array<string, mixed>}  $prepared
     */
    public function checkInput(string $question, string $answer, array $cited, array $picks, array $prepared): string
    {
        ['byRef' => $byRef, 'productsByRef' => $productsByRef, 'analysis' => $analysis] = $prepared;

        return (string) json_encode([
            'question' => $question,
            'answer' => $answer,
            'passages' => array_map(fn (string $ref): array => ['title' => $byRef[$ref]['title'], 'text' => $byRef[$ref]['text']], $cited),
            'picks' => array_map(fn (array $pick): array => ['ref' => $pick['ref'], 'title' => $pick['title'], 'about' => $productsByRef[$pick['ref']]['about'], 'why' => $pick['why']] + array_intersect_key($this->forModel([$pick['ref'] => $productsByRef[$pick['ref']]], $analysis)[0], array_flip(['brand', 'in_stock', 'on_sale', 'price', 'option_prices', 'price_rank'])), $picks),
            'analysis' => $analysis,
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * What the checker's reply leaves of an answer and its picks: picks that do not fit go, an
     * answer the pages do not support or off the question goes, and nothing stays of an answer
     * not safe for every shopper or of an attempt to instruct the assistant.
     *
     * @param  array<string, mixed>  $check  the checker's JSON
     * @param  list<array<string, mixed>>  $picks
     * @return array{answer: string, picks: list<array<string, mixed>>, safe: bool, injection: bool, refusal: string|null, verdict: string}
     */
    public static function applyCheck(array $check, string $answer, array $picks): array
    {
        if (isset($check['decision'])) {
            return self::applyDecision($check, $answer, $picks);
        }

        $before = ['answer' => $answer !== '', 'picks' => $picks !== []];
        $verdict = [];

        if (($check['picks_fit'] ?? null) !== true) {
            $picks = [];
            $verdict[] = 'picks';
        }
        if (($check['supported'] ?? null) !== true || ($check['on_topic'] ?? null) !== true) {
            $answer = '';
            $verdict[] = ($check['on_topic'] ?? null) !== true ? 'off_topic' : 'unsupported';
        }

        $safe = ($check['safe'] ?? null) === true;
        $injection = ($check['injection'] ?? false) === true;
        if (! $safe || $injection) {
            $answer = '';
            $picks = [];
            $verdict[] = $injection ? 'injection' : 'unsafe';
        }

        $refusal = $answer === '' && $picks === [] ? 'check_'.implode('_', $verdict ?: ['empty']) : null;

        return ['answer' => $answer, 'picks' => $picks, 'safe' => $safe, 'injection' => $injection, 'refusal' => $refusal, 'verdict' => match (true) {
            $refusal !== null => 'rejected',
            $before['answer'] && $answer === '' => 'answer_removed',
            $before['picks'] && $picks === [] => 'picks_removed',
            default => 'kept',
        }];
    }

    /**
     * What a checker's decision leaves (verify_site v6 on): approve keeps the answer; revise puts its
     * focused fix in its place (no longer than the answer and a little, or the answer stays when every
     * issue is minor); reject leaves nothing. A pick that does not fit goes alone, and a wrong reason is
     * replaced by the checker's from the product's description: the products are judged apart from the
     * reasons. Unsafe or an injection leaves nothing, as before. The issues come back for the record.
     *
     * @param  array<string, mixed>  $check
     * @param  list<array<string, mixed>>  $picks
     * @return array{answer: string, picks: list<array<string, mixed>>, safe: bool, injection: bool, refusal: string|null, verdict: string, decision: string, issues: list<array{text: string, kind: string, severity: string, pick?: string}>, revised: bool}
     */
    private static function applyDecision(array $check, string $answer, array $picks): array
    {
        $before = ['answer' => $answer !== '', 'picks' => $picks !== []];
        $decision = in_array($check['decision'], ['approve', 'revise', 'reject'], true) ? (string) $check['decision'] : 'reject';
        $safe = ($check['safe'] ?? null) === true;
        $injection = ($check['injection'] ?? false) === true;
        $issues = array_values(array_map(fn (array $i): array => array_filter([
            'text' => mb_substr(trim((string) ($i['text'] ?? '')), 0, 200),
            'kind' => in_array($i['kind'] ?? null, ['unsupported', 'contradiction', 'numeric', 'unfit_pick', 'wrong_reason', 'off_topic', 'safety'], true) ? (string) $i['kind'] : 'unsupported',
            'severity' => in_array($i['severity'] ?? null, ['minor', 'major', 'critical'], true) ? (string) $i['severity'] : 'major',
            'pick' => isset($i['pick']) ? mb_substr((string) $i['pick'], 0, 8) : null,
        ], fn ($v): bool => $v !== null && $v !== ''), array_slice(array_filter((array) ($check['issues'] ?? []), 'is_array'), 0, 6)));

        if (! $safe || $injection || in_array('critical', array_column($issues, 'severity'), true) && in_array('safety', array_column($issues, 'kind'), true)) {
            return ['answer' => '', 'picks' => [], 'safe' => $safe && ! $injection, 'injection' => $injection, 'refusal' => 'check_'.($injection ? 'injection' : 'unsafe'), 'verdict' => 'rejected', 'decision' => 'reject', 'issues' => $issues, 'revised' => false];
        }

        // Each pick judged apart: one that does not fit goes, a wrong reason is the checker's from its description.
        $verdicts = [];
        foreach ((array) ($check['picks'] ?? []) as $p) {
            if (is_array($p) && isset($p['ref'])) {
                $verdicts[(string) $p['ref']] = $p;
            }
        }
        $kept = [];
        foreach ($picks as $pick) {
            $said = $verdicts[(string) ($pick['ref'] ?? '')] ?? null;
            if (($said !== null && ($said['fits'] ?? true) === false) || ($said === null && $verdicts === [] && ($check['picks_fit'] ?? true) === false)) {
                continue;
            }
            $why = trim((string) ($said['why'] ?? ''));
            $kept[] = $why === '' ? $pick : ['why' => mb_substr($why, 0, 300)] + $pick;
        }
        $picks = $kept;

        $revised = false;
        if ($decision === 'reject') {
            $answer = '';
            $picks = [];
        } elseif ($decision === 'revise') {
            $fix = trim((string) ($check['revised'] ?? ''));
            // A fix may correct and say what the given facts say instead (code checks its numbers, measures
            // and contacts again); one far longer than the answer adds what was not asked for: not taken.
            if ($fix !== '' && mb_strlen($fix) <= mb_strlen($answer) * self::REVISED_GROWTH + 80) {
                $answer = $fix;
                $revised = true;
            } elseif (array_diff(array_column($issues, 'severity'), ['minor']) !== []) {
                // A material issue and no fix to put in its place: only the sentences the issues name go,
                // the rest stays if enough is left; else the answer goes, the fitting picks stay.
                $faulty = array_values(array_filter(array_map(fn (array $i): string => $i['severity'] === 'minor' ? '' : mb_strtolower((string) ($i['text'] ?? '')), $issues), fn (string $t): bool => mb_strlen($t) >= 4));
                $answer = $faulty === [] ? '' : self::withoutSentences($answer, function (string $sentence) use ($faulty): bool {
                    $sentence = mb_strtolower($sentence);
                    foreach ($faulty as $text) {
                        if (str_contains($sentence, $text) || str_contains($text, trim($sentence, " \t\n.!?"))) {
                            return true;
                        }
                    }

                    return false;
                })[0];
            }
        }

        $refusal = $answer === '' && $picks === [] ? 'check_'.($decision === 'reject' ? (($check['on_topic'] ?? true) === false ? 'off_topic' : 'rejected') : 'unfixed') : null;

        return ['answer' => $answer, 'picks' => $picks, 'safe' => true, 'injection' => false, 'refusal' => $refusal, 'decision' => $decision, 'issues' => $issues, 'revised' => $revised, 'verdict' => match (true) {
            $refusal !== null => 'rejected',
            $revised => 'revised',
            $before['answer'] && $answer === '' => 'answer_removed',
            $before['picks'] && count($picks) < count($verdicts === [] ? $picks : $verdicts) => 'picks_removed',
            default => 'kept',
        }];
    }

    /**
     * What code can say about the products found, so the model need not guess: which are in stock
     * from the cheapest up, which are on sale, which brands, and what the question asks for. A
     * superlative is computed here, never by a model. "The most recommended" is what the store's
     * customers choose most (ProductsPopular): its order, of the products found, when it asks so.
     *
     * @param  array<string, array{price: float|null, in_stock: bool, on_sale: bool, brand: string|null}>  $productsByRef
     * @param  array<string, float>  $popular  external id => how popular (ProductsPopular), read only when it asks so
     * @return array{asks: string|null, in_stock_cheapest_first: list<string>, out_of_stock: list<string>, on_sale: list<string>, brands: list<string>, popular_first?: list<string>}
     */
    public static function analysis(string $question, array $productsByRef, array $popular = []): array
    {
        $priced = array_filter($productsByRef, fn (array $p): bool => $p['in_stock'] && $p['price'] !== null && $p['price'] > 0);
        uasort($priced, fn (array $a, array $b): int => $a['price'] <=> $b['price']);
        $text = mb_strtolower($question);
        $asks = match (true) {
            preg_match('/זול|במחיר\s+נמוך|הכי\s+משתלם|cheap|budget/u', $text) === 1 => 'cheapest',
            preg_match('/יקר|expensive/u', $text) === 1 => 'most_expensive',
            preg_match('/מבצע|הנחה|sale|discount/u', $text) === 1 => 'on_sale',
            self::asksPopular($text) => 'popular',
            default => null,
        };
        $out = [
            'asks' => $asks,
            'in_stock_cheapest_first' => array_keys($priced),
            'out_of_stock' => array_keys(array_filter($productsByRef, fn (array $p): bool => ! $p['in_stock'])),
            'on_sale' => array_keys(array_filter($productsByRef, fn (array $p): bool => $p['on_sale'] && $p['in_stock'])),
            'brands' => array_values(array_unique(array_filter(array_map(fn (array $p): ?string => $p['brand'] ?: null, $productsByRef)))),
        ];
        if ($asks === 'popular') {
            $ranked = array_filter($productsByRef, fn (array $p): bool => ($popular[(string) $p['external_id']] ?? 0) > 0);
            uasort($ranked, fn (array $a, array $b): int => $popular[(string) $b['external_id']] <=> $popular[(string) $a['external_id']]);
            $out['popular_first'] = array_keys($ranked);
        }

        return $out;
    }

    /** "מה הספר הכי מומלץ", "הכי נמכר", "רב מכר", "best seller": what the store's customers choose most. */
    private const POPULAR = '/הכי\s+מומל[צץ]\S*|מומל[צץ]\S*\s+ביותר|הכי\s+פופולר\S*|פופולרי\S*\s+ביותר|רב[\s\-־]?מכר\S*|הכי\s+נמכר\S*|נמכר\S*\s+ביותר|הכי\s+(?:קונים|אוהבים|מבוקש\S*)|best[\s-]?sell\w*|most\s+(?:popular|recommended|sold)/u';

    public static function asksPopular(string $question): bool
    {
        return preg_match(self::POPULAR, mb_strtolower($question)) === 1;
    }

    /**
     * The store's most popular products a question asks for (ProductsPopular), of the kind its other words name ("מה
     * הספר הכי מומלץ": its books), else of the whole store; with each one's score.
     *
     * @return array<string, float>
     */
    private function popularFor(string $shopId, string $question): array
    {
        if (! app()->bound(ProductsPopular::class)) {
            return [];
        }
        $kind = trim((string) preg_replace(self::POPULAR, ' ', mb_strtolower($question)));
        $named = $kind !== '' && app()->bound(ProductsNamed::class) ? app(ProductsNamed::class)->all($shopId, $kind) : [];

        return app(ProductsPopular::class)->scores($shopId, $named === [] ? null : $named);
    }

    /**
     * A superlative or a price range answered in code: the products found that are what the
     * question names (a drill, not "a bit for a drill"), in stock, inside the price the shopper
     * wrote, ordered by price or on sale. "עגילים עד 500" is the cheapest earrings in stock up to
     * 500, from the products the search already kept to that price. "מה הכי זול באתר" names no
     * product: the cheapest in the whole store, not of the few its words happened to find. Null
     * when the question asks for neither or none of the products is what it names: then a model
     * reads it.
     *
     * After a search, "מה הכי זול?" is a follow-up: the cheapest of what the turn before showed.
     *
     * @param  list<string>  $shown
     * @return array<string, mixed>|null
     */
    private function computed(string $question, string $locale, array $shown, bool $followUp = false): ?array
    {
        $asks = self::analysis($question, [])['asks'];
        // what is recommended most: the writer picks it by what the store's customers choose (analysis' popular_first)
        if ($asks === 'popular') {
            return null;
        }
        ['min' => $min, 'max' => $max] = app()->bound(PriceRange::class) ? app(PriceRange::class)->read($question) : ['min' => null, 'max' => null];
        $asks ??= $min !== null || $max !== null ? 'in_range' : null;
        // A range with another wish ("the biggest diamond", "the best for a gift") is not answered by price alone:
        // the writer chooses among the products inside it (withinPrice()).
        if ($asks === 'in_range' && preg_match('/הכי|ביותר|\b(?:most|best|biggest|largest|smallest|nicest)\b/iu', $question) === 1) {
            return null;
        }
        $named = self::named($question);
        $wholeStore = ! $followUp && $named === [] && in_array($asks, ['cheapest', 'most_expensive'], true);
        // A question that names a product is answered from every product of the store the words name, as the
        // store's search reads them (categories, stones, synonyms): "איזו טבעת עם יהלום הכי זולה" from all the
        // diamond rings, never from the two a shopper had on screen (Rocks, 24.10: it named the cheaper of two).
        $byName = (! $followUp && $named !== [] && $asks !== null && app()->bound(ProductsNamed::class)
            ? app(ProductsNamed::class)->all($this->tenant->require(), $question)
            : null) ?: null;

        $productsByRef = [];
        foreach ($this->candidates($wholeStore ? $this->storeWide($asks, $min, $max) : ($byName ?: $shown)) as $i => $product) {
            $productsByRef['p'.($i + 1)] = $product;
        }

        if ($asks === null || $productsByRef === []) {
            return null;
        }

        // What the question names. Found by the store's search: what it found (hoops titled "חישוקי זהב" are
        // earrings too, "תכשיט" names a category, not a title), less what is only for it: "the cheapest drill"
        // is never a bit "למברגה". Among what was shown, with a price, the same; without one, the title must
        // name it.
        $byWords = $byName === null && ! $wholeStore && ($min !== null || $max !== null) && app()->bound(ProductsNamed::class)
            ? array_flip(app(ProductsNamed::class)->among($this->tenant->require(), $question, array_column($productsByRef, 'external_id')))
            : null;
        $fit = array_filter($productsByRef, fn (array $p): bool => $p['in_stock'] && $p['price'] !== null && $p['price'] > 0
            && ($min === null || $p['price'] >= $min) && ($max === null || $p['price'] <= $max)
            && ($byName !== null || ($byWords !== null ? isset($byWords[(string) $p['external_id']]) : ($named === [] || self::titleNames($p['title'], $named))))
            && ($asks !== 'on_sale' || $p['on_sale']));

        if ($byName !== null) {
            $fit = array_filter($fit, fn (array $p): bool => ! self::onlyFor($p['title'], $named));
        }

        if ($fit === []) {
            return null;
        }

        uasort($fit, fn (array $a, array $b): int => $asks === 'most_expensive' ? $b['price'] <=> $a['price'] : $a['price'] <=> $b['price']);
        $picks = [];

        foreach (array_slice(array_values($fit), 0, self::MAX_COMPUTED) as $at => $product) {
            $picks[] = ['external_id' => $product['external_id'], 'title' => $product['title'], 'why' => (string) __('assistant::answers.site.computed.why_'.$asks.($at === 0 ? '' : '_next'), [], $locale)];
        }

        return [
            'outcome' => AssistantAnswer::ANSWERED,
            'answer' => (string) __('assistant::answers.site.computed.'.$asks.($wholeStore ? '_store' : ''), ['title' => $picks[0]['title']], $locale),
            'from' => 'code',
            'sources' => [],
            'picks' => $this->present($picks),
        ];
    }

    /**
     * The store's cheapest (or dearest) products in stock, inside the price if one was written. What
     * is not merchandise stays out: a gift card by its name, and, in a store that gives most of its
     * products a kind (Shopify's product type), whatever has none, such as a ring sizer at 5.
     *
     * @return list<string> external ids, in price order
     */
    private function storeWide(string $asks, ?float $min, ?float $max): array
    {
        $base = CatalogProduct::query()->whereNull('removed_at')->where('in_stock', true)->where('price', '>', 0)
            ->when($min !== null, fn ($q) => $q->where('price', '>=', $min))
            ->when($max !== null, fn ($q) => $q->where('price', '<=', $max));
        $typed = fn ($q) => $q->whereNotNull('payload->product_type')->where('payload->product_type', '!=', '');
        $all = (clone $base)->count();
        $query = $all > 0 && 2 * $typed(clone $base)->count() >= $all ? $typed(clone $base) : $base;

        return $query->orderBy('price', $asks === 'most_expensive' ? 'desc' : 'asc')->limit(self::MAX_COMPUTED * 5)->get(['external_id', 'title'])
            ->reject(fn (CatalogProduct $p): bool => preg_match('/gift\s*card|כרטיס\s+מתנה|גיפט\s*קארד|שובר\s+מתנה|voucher/iu', (string) $p->title) === 1)
            ->take(self::MAX_COMPUTED)->map(fn (CatalogProduct $p): string => (string) $p->external_id)->values()->all();
    }

    /** Whether a question points at products already in front of the shopper: "מהם", "ביניהם", "these". */
    public static function pointsBack(string $question): bool
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(Question::normalize($question)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_intersect($words, self::REFERS) !== [];
    }

    /**
     * Whether a question is about the products the conversation just showed: it points back at them
     * ("איזה מהם"), or names no product at all ("מה הכי זול?"). Not when it asks about the whole
     * store ("מה הכי זול באתר").
     */
    public static function followsUp(string $question): bool
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(Question::normalize($question)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (array_intersect($words, self::STORE_WIDE) !== []) {
            return false;
        }

        return array_intersect($words, self::REFERS) !== [] || self::named($question) === [];
    }

    /**
     * The words a question names its product with: no question, filler or superlative words,
     * each by its stem. "איזו מברגה הכי זולה" names "מברג".
     *
     * @return list<string>
     */
    private static function named(string $question): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(Question::normalize($question)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = [];

        foreach ($words as $word) {
            // Not an amount or its currency either: "עד 500 ש״ח" names no product.
            if (mb_strlen($word) >= 3 && ! in_array($word, self::NOT_NAMES, true) && preg_match('/\d/u', $word) !== 1
                && preg_match('/^(זול|יקר|במבצע|מבצע|הנחה|שקל|cheap|expensive)/u', $word) !== 1) {
                $out[] = self::stem($word);
            }
        }

        return array_values(array_unique($out));
    }

    /** Whether a title has a word that starts with one of the named stems ("למברגה" does not). */
    private static function titleNames(string $title, array $named): bool
    {
        foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($title), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            foreach ($named as $stem) {
                if (str_starts_with($word, $stem)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Whether a title names it only as what it is for: "ביט למברגה", "קופסה לטבעת", never the thing itself. */
    private static function onlyFor(string $title, array $named): bool
    {
        if ($named === [] || self::titleNames($title, $named)) {
            return false;
        }

        foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($title), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            foreach ($named as $stem) {
                if (str_starts_with($word, 'ל'.$stem) || str_starts_with($word, 'for'.$stem)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** מברגה, מברגות → מברג. */
    private static function stem(string $word): string
    {
        return mb_strlen($word) > 3 ? (string) preg_replace('/(ות|ים|ה|ת)$/u', '', $word) : $word;
    }

    /**
     * The products as the model sees them: what they are, code's ranks, and their prices as the store shows them (each
     * choice's own when the price depends on one), so a shopper who asks what something costs is told. The writer may
     * quote them only as given; code holds every sum in an answer to them (screen()).
     *
     * @param  array<string, array<string, mixed>>  $productsByRef
     * @param  array{in_stock_cheapest_first: list<string>}  $analysis
     * @return list<array<string, mixed>>
     */
    private function forModel(array $productsByRef, array $analysis): array
    {
        $rank = array_flip($analysis['in_stock_cheapest_first']);
        $out = [];

        foreach ($productsByRef as $ref => $p) {
            $out[] = array_filter([
                'ref' => $ref,
                'title' => $p['title'],
                'about' => $p['about'],
                'category' => $p['category'],
                'brand' => $p['brand'] ?? null,
                'in_stock' => $p['in_stock'],
                'on_sale' => $p['on_sale'] ?: null,
                'price' => ($p['price'] ?? 0) > 0 ? Money::price((float) $p['price'], (string) ($p['currency'] ?? 'ILS')) : null,
                'option_prices' => ($p['option_prices'] ?? []) ?: null,
                'price_rank' => isset($rank[$ref]) ? $rank[$ref] + 1 : null,
            ], fn ($v): bool => $v !== null && $v !== '');
        }

        return $out;
    }

    /**
     * The products the shopper was shown, in the order shown, with the little the writer needs.
     *
     * @param  list<string>  $shown
     * @return list<array{external_id: string, title: string, about: string, category: string}>
     */
    /**
     * A price the question names is code's, never the writer's: it is given ranks, not prices. So what was shown or
     * named outside the range never reaches it, and when none of that is inside, the store's own products the words
     * name are, inside the range, in stock first (Rocks, 25.10: "the biggest diamond between 2000 and 2500" was given
     * two rings at 1,000, and the writer said the site shows no prices). A question with no price: as it was.
     *
     * @param  list<array<string, mixed>>  $candidates
     * @return list<array<string, mixed>>
     */
    private function withinPrice(string $shopId, string $question, array $candidates): array
    {
        ['min' => $min, 'max' => $max] = app()->bound(PriceRange::class) ? app(PriceRange::class)->read($question) : ['min' => null, 'max' => null];

        if ($min === null && $max === null) {
            return $candidates;
        }

        $within = fn (array $p): bool => $p['price'] !== null && $p['price'] > 0 && ($min === null || $p['price'] >= $min) && ($max === null || $p['price'] <= $max);
        $inside = array_values(array_filter($candidates, $within));

        if ($inside === [] && app()->bound(ProductsNamed::class) && ($named = app(ProductsNamed::class)->all($shopId, $question)) !== []) {
            // in the order the store's search named them, what is in stock first
            $stock = CatalogProduct::query()->whereNull('removed_at')->whereIn('external_id', $named)->where('price', '>', 0)
                ->when($min !== null, fn ($q) => $q->where('price', '>=', $min))
                ->when($max !== null, fn ($q) => $q->where('price', '<=', $max))
                ->pluck('in_stock', 'external_id');
            $ids = array_values(array_filter($named, fn (string $id): bool => isset($stock[$id])));
            usort($ids, fn (string $a, string $b): int => (int) $stock[$b] <=> (int) $stock[$a]);
            $inside = $this->candidates(array_slice($ids, 0, self::MAX_PRODUCTS));
        }

        return $inside;
    }

    private function candidates(array $shown): array
    {
        if ($shown === []) {
            return [];
        }

        $products = CatalogProduct::query()->whereNull('removed_at')->whereIn('external_id', $shown)->get()->keyBy('external_id');
        // A question about one product or two (from its page, a comparison) reads their descriptions
        // whole enough to say what is special; a list of results needs a line each.
        $aboutChars = count($shown) <= 2 ? 1500 : 300;
        $out = [];

        foreach ($shown as $id) {
            $product = $products->get($id);

            if ($product === null) {
                continue;
            }

            $out[] = [
                'external_id' => (string) $product->external_id,
                'title' => (string) $product->title,
                'about' => mb_substr(self::about($product), 0, $aboutChars),
                'category' => implode(' > ', $product->categoryPaths()[0] ?? []),
                'brand' => $product->brand,
                'in_stock' => (bool) $product->in_stock,
                'on_sale' => (bool) $product->on_sale,
                'price' => $product->price !== null ? (float) $product->price : null,
                'currency' => (string) ($product->currency ?: 'ILS'),
                'option_prices' => self::optionPrices($product),
            ] + self::details($product);
        }

        return $out;
    }

    /**
     * What the product says about itself: its short description where the store writes one (WooCommerce),
     * else its description (Shopify has no short one).
     */
    private static function about(CatalogProduct $product): string
    {
        foreach ([$product->shortDescription(), $product->description()] as $text) {
            $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($text)));
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    /**
     * What a product says besides its description, for the kinds of question that ask about it in
     * dynamic mode: its options, its sizes, its measures, and which options are in stock. Full mode
     * never sends these.
     *
     * @return array{sku: string, attributes: list<string>, sizes: list<string>, measures: list<string>, options_in_stock: list<string>}
     */
    /** Choices a price depends on, at most this many, each with its price ("18 מ\"מ: ₪350"). */
    private const OPTION_PRICES = 12;

    /**
     * A product whose price depends on a choice (a thickness, a size): each choice with its price, as the store sells
     * it; none when every choice costs the same. What the writer may quote, exactly, and code holds it to.
     *
     * @return list<string>
     */
    private static function optionPrices(CatalogProduct $product): array
    {
        $currency = (string) ($product->currency ?: 'ILS');
        $out = [];
        $prices = [];

        foreach ((array) ($product->payload['variations'] ?? []) as $variation) {
            $price = $variation['price']['price'] ?? $variation['price'] ?? null;
            $choice = implode(' / ', array_filter(array_map(fn ($a): string => trim((string) (is_array($a) ? ($a['value'] ?? '') : $a)), (array) ($variation['attributes'] ?? []))));
            if (! is_numeric($price) || (float) $price <= 0 || $choice === '') {
                continue;
            }
            $prices[] = (float) $price;
            $out[$choice] = $choice.': '.Money::price((float) $price, $currency);
        }

        return count(array_unique($prices)) > 1 ? array_slice(array_values($out), 0, self::OPTION_PRICES) : [];
    }

    private static function details(CatalogProduct $product): array
    {
        $attributes = array_map(fn (array $a): string => $a['name'].': '.implode(', ', $a['values']), $product->storeAttributes());
        $sizes = [];
        foreach ($product->storeAttributes() as $attribute) {
            if (preg_match('/^(size|מיד|גודל)/iu', (string) $attribute['name']) === 1) {
                $sizes = [...$sizes, ...$attribute['values']];
            }
        }

        $dimensions = (array) ($product->payload['dimensions'] ?? []);
        $measures = [];
        foreach (['weight' => 'weight_unit', 'length' => 'dimension_unit', 'width' => 'dimension_unit', 'height' => 'dimension_unit'] as $field => $unit) {
            if (is_numeric($dimensions[$field] ?? null) && (float) $dimensions[$field] > 0) {
                $measures[] = $field.': '.$dimensions[$field].' '.($dimensions[$unit] ?? '');
            }
        }

        $inStock = [];
        foreach ((array) ($product->payload['variations'] ?? []) as $variation) {
            if (($variation['stock']['in_stock'] ?? false) === true) {
                $inStock = [...$inStock, ...array_map(fn ($a): string => (string) ($a['value'] ?? ''), (array) ($variation['attributes'] ?? []))];
            }
        }

        return [
            'sku' => (string) $product->sku,
            'attributes' => $attributes,
            'sizes' => array_values(array_unique(array_map('strval', $sizes))),
            'measures' => array_map('trim', $measures),
            'options_in_stock' => array_values(array_unique(array_filter($inStock))),
        ];
    }

    /**
     * Code's gate on the picks: only products it offered, each once, at most three, each with why.
     *
     * @param  array<int, mixed>  $raw
     * @param  array<string, array{external_id: string, title: string, about: string, category: string}>  $productsByRef
     * @return list<array{ref: string, external_id: string, title: string, why: string}>
     */
    private function picks(array $raw, array $productsByRef): array
    {
        $out = [];
        $seen = [];

        foreach ($raw as $pick) {
            $ref = is_array($pick) ? (string) ($pick['ref'] ?? '') : '';
            $why = is_array($pick) ? self::withoutRefs(mb_substr(trim((string) ($pick['why'] ?? '')), 0, 160)) : '';

            if (! isset($productsByRef[$ref]) || isset($seen[$ref]) || $why === '' || Question::hasContactDetails($why) || self::hasRef($why)) {
                continue;
            }

            $seen[$ref] = true;
            $out[] = ['ref' => $ref, 'external_id' => $productsByRef[$ref]['external_id'], 'title' => $productsByRef[$ref]['title'], 'why' => $why];

            if (count($out) >= self::MAX_PICKS) {
                break;
            }
        }

        return $out;
    }

    /**
     * Picks as the shopper sees them: still on sale, with their link and picture today.
     *
     * @param  list<array{external_id: string, title: string, why: string}>  $picks
     * @return list<array{external_id: string, title: string, url: string|null, image: string|null, why: string}>
     */
    private function present(array $picks): array
    {
        if ($picks === []) {
            return [];
        }

        $products = CatalogProduct::query()->whereNull('removed_at')->whereIn('external_id', array_column($picks, 'external_id'))->get()->keyBy('external_id');
        $out = [];

        foreach ($picks as $pick) {
            $product = $products->get($pick['external_id']);

            if ($product !== null) {
                $out[] = ['external_id' => (string) $product->external_id, 'title' => (string) $product->title, 'url' => $product->url, 'image' => $product->image_url, 'why' => (string) $pick['why']];
            }
        }

        return $out;
    }

    /**
     * Every question asked is logged with what came of it; the reply carries its id, so what the
     * shopper does next (the WhatsApp offer, a pick) is added to it.
     *
     * @param  list<string>  $products
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    /**
     * The page an answer rests on that the question itself names: a page of the store (not a product,
     * not the store's words about itself) every word of whose title is in the question, as Hebrew writes
     * it (a prefix letter before it, final letters as regular ones): "מנויים" for "מה זה תוכנית מנויים?",
     * "מדיניות החזרות" for "מה מדיניות ההחזרות?". Null when none is: no button is better than a wrong one.
     *
     * @param  list<array{title?: string, url?: string|null, type?: string}>  $sources
     * @return array{title: string, url: string}|null
     */
    public static function namedPage(string $question, array $sources): ?array
    {
        $plain = fn (string $text): string => strtr(mb_strtolower((string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text)), ['ך' => 'כ', 'ם' => 'מ', 'ן' => 'נ', 'ף' => 'פ', 'ץ' => 'צ']);
        $asked = ' '.(string) preg_replace('/\s+/u', ' ', $plain($question)).' ';

        foreach ($sources as $source) {
            $url = (string) ($source['url'] ?? '');

            if (($source['type'] ?? '') !== 'content' || ! str_starts_with($url, 'http')) {
                continue;
            }

            $words = array_values(array_filter(preg_split('/\s+/u', $plain((string) ($source['title'] ?? ''))) ?: [], fn (string $w): bool => mb_strlen($w) >= 3));
            $named = $words !== [] && array_filter($words, fn (string $w): bool => preg_match('/\s[והבלמשכ]{0,2}'.preg_quote($w, '/').'/u', $asked) !== 1) === [];

            if ($named) {
                return ['title' => (string) $source['title'], 'url' => $url];
            }
        }

        return null;
    }

    /**
     * An answer that stops in the middle of a sentence ("…and bring in local travel professio") ends at its last whole
     * sentence instead, where that keeps half of it or more; else it is as it was (a short answer may end with no full
     * stop). One that ends as a sentence does (a full stop, a question or exclamation mark, a colon before a list, a
     * closing quote) is as it was.
     */
    public static function endsWhole(string $answer): string
    {
        $text = rtrim($answer);
        // a cut comes in a long answer: a short one with no full stop is as it was ("Try the classic band")
        if (mb_strlen($text) < 300 || preg_match('/[.!?…:)"״\'׳”»\]]$/u', $text) === 1) {
            return $answer;
        }
        if (preg_match_all('/[.!?…](?=\s|$)/u', $text, $ends, PREG_OFFSET_CAPTURE) > 0) {
            $last = end($ends[0]);
            $cut = substr($text, 0, $last[1] + strlen($last[0]));
            if (mb_strlen($cut) >= mb_strlen($text) * 0.5) {
                return $cut;
            }
        }

        return $answer;
    }

    /** The most products of an answer's set kept with its ask: what the team reads, not the whole list. */
    private const SHOWN_KEPT = 24;

    /**
     * The products an answer showed besides its picks, as external ids: those its words named, then a set it listed.
     *
     * @param  array<string, mixed>  $result
     * @return list<string>
     */
    private static function shownBy(array $result): array
    {
        $picked = array_flip(array_map(fn (array $p): string => (string) $p['external_id'], (array) ($result['picks'] ?? [])));
        $ids = [...(array) ($result['named']['ids'] ?? []), ...(array) ($result['set']['ids'] ?? [])];
        $ids = array_map(fn ($id): string => str_starts_with((string) $id, 'p:') ? substr((string) $id, 2) : (string) $id, $ids);

        return array_slice(array_values(array_unique(array_filter($ids, fn (string $id): bool => $id !== '' && ! isset($picked[$id])))), 0, self::SHOWN_KEPT);
    }

    private function logged(string $shopId, string $question, array $products, array $result): array
    {
        $whatsapp = $result['outcome'] !== AssistantAnswer::ANSWERED && $result['outcome'] !== 'invalid'
            && Features::enabled('assistant.search_whatsapp', $shopId)
            // The shop may have chosen leaving details, or only "not found", for what the site does not answer.
            && Settings::get('assistant.no_answer', $shopId) === NoAnswer::TEAM;

        if ($result['outcome'] === 'invalid') {
            unset($result['reason']);

            return $result;
        }

        // A trial is logged nowhere: what the ask would have said goes back to the check screen instead.
        if ($this->trial) {
            $answered = $this->answered ?? [];
            $this->answered = null;

            return $result + ['trial' => [
                'reason' => $result['reason'] ?? null,
                'failure' => AnswerFailure::classify((string) $result['outcome'], $result['reason'] ?? null, $answered['verdict'] ?? null, (array) ($answered['trace'] ?? [])),
                'looks' => $this->looked,
            ] + $answered, 'whatsapp' => $whatsapp] + ($result['outcome'] === AssistantAnswer::ANSWERED ? [] : ['no_answer' => NoAnswer::offer($shopId)]);
        }

        $ask = AssistantSearchAsk::query()->create([
            'shop_id' => $shopId,
            'day' => now()->toDateString(),
            // Never personal details, and nothing of a question about hurting oneself: this log is
            // read by the team and sent to the nightly review.
            'question' => ($result['reason'] ?? null) === 'support' ? '[support]' : mb_substr(Privacy::redact($question)['text'], 0, 500),
            'products' => $products,
            'outcome' => $result['outcome'],
            'from' => $result['from'],
            // Why it got no answer, for the operator: what refused it.
            'reason' => $result['reason'] ?? null,
            'answer_id' => $result['answer_id'] ?? null,
            // An answer kept nowhere else (about the page's product, or the turn before), for the nightly review.
            'answer' => ($result['answer_id'] ?? null) === null && $result['outcome'] === AssistantAnswer::ANSWERED
                ? mb_substr(Privacy::redact((string) ($result['answer'] ?? ''))['text'], 0, 2000) : null,
            'picks' => array_map(fn (array $p): array => ['external_id' => $p['external_id'], 'title' => $p['title'], 'why' => $p['why']], (array) ($result['picks'] ?? [])),
            // every other product the answer showed: those its words named, and a set it listed (the team reads them)
            'shown' => self::shownBy($result) ?: null,
            'whatsapp_shown' => $whatsapp,
            // How it was answered (ask()), and why it failed as code reads it; the nightly review may add its own.
            ...($this->answered ?? []),
            'failure' => AnswerFailure::classify((string) $result['outcome'], $result['reason'] ?? null, $this->answered['verdict'] ?? null, (array) ($this->answered['trace'] ?? [])),
        ]);
        $this->answered = null;

        unset($result['answer_id'], $result['reason']);

        // What the storefront offers instead of an answer: the team, leaving details, or nothing (NoAnswer).
        $then = $result['outcome'] === AssistantAnswer::ANSWERED ? [] : ['no_answer' => NoAnswer::offer($shopId)];

        return $result + ['ask_id' => $ask->id, 'whatsapp' => $whatsapp] + $then;
    }

    /**
     * The pages the answer was written from, as the shopper can open them.
     *
     * @param  list<array{source: string, source_id: string, title: string}>  $cited
     * @return list<array{title: string, url: string|null, type: string}>
     */
    /**
     * The store's own words for a question, quoted from the pages the writer cited, when its answer
     * could not be checked: from each page, the sentences that share the most words with the question
     * (a word of 3 letters or more, its Hebrew prefix and final letters aside), in the page's order, as
     * they are. Code only. A page none of whose sentences shares a word is not quoted.
     *
     * @param  list<array<string, mixed>>  $passages
     * @param  callable(list<array<string, mixed>>): list<array{title: string, url: string|null, type: string}>  $sources
     * @return list<array{title: string, url: string|null, text: string}>
     */
    public static function quotes(string $question, array $passages, callable $sources): array
    {
        $plain = fn (string $text): string => strtr(mb_strtolower($text), ['ך' => 'כ', 'ם' => 'מ', 'ן' => 'נ', 'ף' => 'פ', 'ץ' => 'צ']);
        $words = [];
        foreach (preg_split('/[^\p{L}\p{N}]+/u', $plain($question), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $word = mb_strlen($word) > 3 ? (string) preg_replace('/^[והבלשמכ]/u', '', $word) : $word;
            if (mb_strlen($word) >= 3) {
                $words[$word] = true;
            }
        }

        $out = [];
        foreach ($passages as $passage) {
            $sentences = preg_split('/(?<=[.!?])\s+|\n+/u', trim((string) ($passage['text'] ?? ''))) ?: [];
            $scored = [];
            foreach ($sentences as $at => $sentence) {
                $sentence = trim($sentence);
                $score = count(array_filter(array_keys($words), fn (string $w): bool => mb_strpos($plain($sentence), $w) !== false));
                if ($score > 0 && $sentence !== '') {
                    $scored[$at] = [$score, $sentence];
                }
            }
            if ($scored === []) {
                continue;
            }
            uasort($scored, fn (array $a, array $b): int => $b[0] <=> $a[0]);
            $best = array_slice($scored, 0, self::QUOTE_SENTENCES, true);
            ksort($best);
            $source = $sources([$passage])[0] ?? ['title' => (string) ($passage['title'] ?? ''), 'url' => null];
            $out[] = ['title' => (string) $source['title'], 'url' => $source['url'] ?? null, 'text' => mb_substr(implode(' ', array_column($best, 1)), 0, self::QUOTE_CHARS)];

            if (count($out) >= self::MAX_SOURCES) {
                break;
            }
        }

        return $out;
    }

    private function sources(array $cited): array
    {
        $out = [];
        $seen = [];

        foreach ($cited as $passage) {
            $key = $passage['source'].'|'.$passage['source_id'];

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $page = $passage['source'] === 'product'
                ? CatalogProduct::query()->whereKey($passage['source_id'])->first(['title', 'url'])
                : CatalogContent::query()->whereKey($passage['source_id'])->first(['title', 'url']);

            // A store's policy page under the name Shopify gives it in English ("Terms of service"), in the store's language.
            $policy = preg_match('~/policies/([a-z-]+)~', (string) ($page?->url ?? ''), $m) === 1 && Lang::has('assistant::answers.policies.'.$m[1]) ? $m[1] : null;
            $title = $policy !== null && preg_match('/^[\x00-\x7F]+$/', (string) ($page->title ?? '')) === 1
                ? (string) __('assistant::answers.policies.'.$policy, [], (string) (Shop::query()->whereKey($this->tenant->require())->value('content_locale') ?: 'he'))
                : (string) ($page->title ?? $passage['title']);
            // what the store says about itself (shipping, returns, its policies): the drawer shows no products beside such an answer
            $out[] = ['title' => $title, 'url' => $page?->url, 'type' => $passage['source'] === 'product' ? 'product' : 'content']
                + (($passage['kind'] ?? null) === 'knowledge' || $policy !== null ? ['store' => true] : []);

            if (count($out) >= self::MAX_SOURCES) {
                break;
            }
        }

        return $out;
    }

    /** @param array{provider: AiProviderName, name: string, model: string} $role */
    /**
     * The kind of question, from what code and the writer made of it before the checker: a
     * superlative, the store's own words (shipping, returns), products, or both. The checker's
     * record is read by it, to learn where a check is worth its cost.
     *
     * @param  list<string>  $cited
     * @param  list<array<string, mixed>>  $picks
     */
    public static function questionType(?string $asks, array $cited, array $picks): string
    {
        return match (true) {
            $asks !== null => 'superlative',
            $cited !== [] && $picks !== [] => 'mixed',
            $picks !== [] => 'products',
            $cited !== [] => 'store_info',
            default => 'none',
        };
    }

    private function call(RunContext $run, array $role, string $prompt, string $input, int $maxOutput, ?string $effort, string $prices): ModelReply
    {
        // A replay asks with the version the operator chose (replay()); everything else with the current one.
        $file = isset($this->promptVersions[$prompt]) ? __DIR__.'/../Prompts/'.$prompt.'.v'.$this->promptVersions[$prompt].'.md' : self::promptFile($prompt);
        $text = $file === null ? '' : (string) @file_get_contents($file);
        $schema = self::schemaFor($file);
        $reply = $schema !== null && $this->models instanceof StructuredChatModel
            ? $this->models->structured($role['provider'], $role['model'], $text, $input, $schema, $maxOutput, $effort)
            : $this->models->json($role['provider'], $role['model'], $text, $input, $maxOutput, $effort);
        $run->usage($role['name'], $role['model'], $reply->inputTokens, $reply->outputTokens, 0, $reply->costUsd($this->price($prices.'_input'), $this->price($prices.'_output')));

        return $reply;
    }

    private function withinDailyLimits(string $shopId, string $visitorHash): bool
    {
        // From a storefront request, its address counts too; from the console there is none. Asked
        // once a question: a set that found nothing hands the question on without counting it twice.
        // a trial uses no visitor's questions (the check screen holds the shop to its own daily trials)
        if ($this->trial) {
            return true;
        }

        return $this->allowed ??= DailyQuestions::allow($shopId, $visitorHash, app()->runningInConsole() && ! app()->runningUnitTests() ? null : request()->ip());
    }

    /**
     * A question that asks to see a whole set of products, answered with all of them: the set's ids
     * for the storefront to show, a line above them, and the store's page of the set when it has one.
     * Null when the question asks no set, or none was found: the question is answered as any other.
     *
     * @return array<string, mixed>|null
     */
    private function set(string $shopId, string $question, string $visitorHash, string $locale): ?array
    {
        if (! app()->bound(ProductSets::class)) {
            return null;
        }

        $found = app(ProductSets::class)->find($shopId, $question, $locale, fn (): bool => $this->withinDailyLimits($shopId, $visitorHash));

        if ($found === null) {
            return null;
        }

        // none of the set itself, nor a close one: the question is answered as any other, by the store's pages and
        // products by meaning, so the shopper still gets a recommendation or an honest close alternative ("there is
        // no fantasy here, but…") and "not found" only when nothing is close at all (answer_site v9). A set chosen
        // again on a changed catalogue may come back empty where it once had near ones: never a dead end then.
        if ($found['ids'] === []) {
            return null;
        }

        return [
            'outcome' => AssistantAnswer::ANSWERED,
            'answer' => $found['answer'],
            'from' => $found['from'],
            'sources' => [],
            'picks' => [],
            'set' => ['ids' => $found['ids'], 'title' => $found['title'], 'near' => $found['near'], 'ask' => $found['ask'], 'search' => $found['search']],
        ] + ($found['page'] !== null ? ['page' => $found['page']] : []);
    }

    /**
     * @param  list<array{title: string, url: string|null, type: string}>  $sources
     * @param  list<array{external_id: string, title: string, why: string}>  $picks
     * @param  list<array{0: ModelReply, 1: string}>  $replies
     */
    /**
     * The question's one saved answer, written or rewritten. A written answer not yet shared is
     * not replaced by a refusal: one visitor's failed check does not undo what others got clean.
     *
     * @param  array<string, mixed>|null  $flags  what the checks said: safe, injection, harmful, personal
     */
    private function save(string $question, string $outcome, ?string $answer, array $sources, array $picks, ?string $model, array $replies, RunContext $run, ?array $flags = null): ?AssistantAnswer
    {
        // A trial is the team's, never the next shopper's.
        if ($this->trial) {
            return null;
        }
        // A question about a product it named, not answered: never kept, so the next asker is answered afresh.
        if ($outcome !== AssistantAnswer::ANSWERED && $this->namedTitles !== []) {
            return null;
        }
        // An answer that says what was asked about is not here ("נמצא כאן רק חטוף", "הספר של שם טוב לא מופיע"):
        // this asker's only. Kept, it answered every later question about that book wrongly, also once it was found.
        if ($outcome === AssistantAnswer::ANSWERED && self::saysMissing((string) $answer)) {
            return null;
        }
        // An answer that quotes a price is today's: prices change, so it is this asker's only, never the next shopper's.
        if ($outcome === AssistantAnswer::ANSWERED && preg_match('~₪|ש"ח|ש״ח|\d\s*שקל|[$€£]\s?\d~u', (string) $answer) === 1) {
            return null;
        }

        $cost = 0.0;
        $input = 0;
        $output = 0;

        foreach ($replies as [$reply, $prices]) {
            $cost += $reply->costUsd($this->price($prices.'_input'), $this->price($prices.'_output'));
            $input += $reply->inputTokens;
            $output += $reply->outputTokens;
        }

        $saved = self::siteAnswers()->where('question_key', Question::key($question))->first();

        if ($saved !== null && $saved->outcome === AssistantAnswer::ANSWERED && $outcome !== AssistantAnswer::ANSWERED) {
            return $saved;
        }

        $fields = [
            'question' => $question,
            'answer' => $answer,
            'outcome' => $outcome,
            'source' => $answer === null && $picks === [] ? null : 'store',
            'sources' => $sources === [] ? null : $sources,
            'picks' => $picks === [] ? null : $picks,
            'prompt_version' => self::PROMPT_VERSION,
            'model' => $model,
            'input_tokens' => $input,
            'output_tokens' => $output,
            'cost_usd' => round($cost, 6),
            'run_id' => $run->runId,
            // a "not on the site" remembers what the scan knew of the products then (stale())
            'flags' => $outcome === AssistantAnswer::NO_INFO ? ['learned' => self::learned()] + (array) $flags : $flags,
            'last_asked_at' => now(),
        ];

        if ($saved !== null) {
            $saved->forceFill($fields)->save();

            return $saved;
        }

        // Counted by visitors from here on: asked_count rises once per different visitor.
        return AssistantAnswer::query()->create($fields + [
            'shop_id' => $this->tenant->require(),
            'question_key' => Question::key($question),
            'asked_count' => 0,
            'visitors' => 0,
        ]);
    }

    /**
     * What the scan has learned of the shop's products, in short: how many different things it can say (kinds of
     * tag, and different values among them). A new kind or a new value (a book's genre, "פנטזיה") changes it; the
     * same tags read again at night, or put on one more product, do not, so a "not on the site" is not paid for again
     * every day (a product added already reopens it on its own).
     */
    private static function learned(): string
    {
        $row = EnrichmentFact::query()->whereNotNull('product_id')->where('status', FactStatus::Approved)
            ->selectRaw("count(distinct key) as kinds, count(distinct (key || ':' || coalesce(value_text, cast(value_number as text), ''))) as said")
            ->toBase()->first();

        return (int) ($row->kinds ?? 0).':'.(int) ($row->said ?? 0);
    }

    /** What the store is, for the topic check: its name and what it sells. */
    private function storeBrief(string $shopId): array
    {
        return [
            'name' => (string) Shop::query()->whereKey($shopId)->value('name'),
            'categories' => CatalogCategory::query()->whereNull('removed_at')->orderByDesc('product_count')->limit(40)->pluck('name')->all(),
        ];
    }

    /** Of the store's own words nearest a question, how many the topic check reads, and how much of each. */
    private const SAYS_PIECES = 3;

    private const SAYS_CHARS = 160;

    /**
     * The store's own products and pages nearest the question by meaning, at the writer's floor, a short piece of
     * each: what the topic check reads beside the store's name and categories. The question's vector is the one the
     * writer's passages use next (QueryVectors), so nothing more is embedded.
     *
     * @return list<array{title: string, text: string}>
     */
    private function storeSays(string $shopId, string $question): array
    {
        $floor = (float) Settings::get('assistant.site_min_similarity', $shopId);

        return array_values(array_map(
            fn (array $p): array => ['title' => (string) $p['title'], 'text' => mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) $p['text'])), 0, self::SAYS_CHARS)],
            array_filter($this->passages->near($shopId, $question, ['product', 'content'], self::SAYS_PIECES), fn (array $p): bool => $p['similarity'] >= $floor),
        ));
    }

    /**
     * Two lists of passages as one: a piece found twice is one, at its best nearness; nearest first, at most $limit.
     *
     * @param  list<array<string, mixed>>  $found
     * @param  list<array<string, mixed>>  $more
     * @return list<array<string, mixed>>
     */
    private static function merged(array $found, array $more, int $limit): array
    {
        $by = [];

        foreach ([...$found, ...$more] as $passage) {
            $key = $passage['source'].'|'.$passage['source_id'].'|'.md5((string) $passage['text']);
            if (! isset($by[$key]) || $by[$key]['similarity'] < $passage['similarity']) {
                $by[$key] = $passage;
            }
        }

        $by = array_values($by);
        usort($by, fn (array $a, array $b): int => [$b['similarity'], $a['source_id']] <=> [$a['similarity'], $b['source_id']]);

        return array_slice($by, 0, max($limit, count($found)));
    }

    private function price(string $name): float
    {
        return (float) Settings::get("assistant.{$name}_usd_per_million");
    }

    /**
     * Whether every sum of money the answer names ("100 ₪", "₪100", "100 שקל") is written in the pages it
     * cites: a fee the store's policy states, never a product's price (the page shows that) or one made up.
     * An answer naming money without a number of it is not.
     */
    public static function amountsFrom(string $answer, string $pages): bool
    {
        preg_match_all('~(\d[\d,.]*)\s*(?:₪|ש"ח|ש״ח|שקל)|(?:₪|ש"ח|ש״ח)\s*(\d[\d,.]*)~u', $answer, $m);
        $amounts = array_values(array_filter([...$m[1], ...$m[2]], fn (string $a): bool => $a !== ''));
        $plain = fn (string $n): string => rtrim(str_replace(',', '', $n), '.');

        return $amounts !== [] && array_filter($amounts, fn (string $a): bool => ! str_contains(str_replace(',', '', $pages), $plain($a))) === [];
    }

    /**
     * Whether every phone number and email address the answer gives is written in the pages it cites:
     * the store's own, as its policy publishes them for cancelling, never one a shopper or the model wrote.
     */
    public static function contactsFrom(string $answer, string $pages): bool
    {
        preg_match_all('~[\w.+-]+@[\w-]+(?:\.[\w-]+)+~u', $answer, $emails);
        preg_match_all('~\+?\d[\d\s-]{7,}\d~u', $answer, $phones);
        $digits = fn (string $s): string => (string) preg_replace('/\D+/', '', $s);
        $pagesDigits = (string) preg_replace('/[\s-]+/', '', $pages);
        $found = [...$emails[0], ...$phones[0]];

        return $found !== []
            && array_filter($emails[0], fn (string $e): bool => mb_stripos($pages, $e) === false) === []
            && array_filter($phones[0], fn (string $p): bool => ! str_contains($pagesDigits, $digits($p))) === [];
    }

    /** A number the pages, the products and the question do not have is one the model made up. */
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

    /**
     * A released prompt of this version, as the writer and the checker read it: a prompt this
     * version did not change is read from the last version that wrote it.
     */
    public static function prompt(string $name): string
    {
        $file = self::promptFile($name);

        return $file === null ? '' : (string) file_get_contents($file);
    }

    /** The newest released version of a prompt, as its file. */
    public static function promptFile(string $name): ?string
    {
        for ($version = self::PROMPT_VERSION; $version > 0; $version--) {
            // 5 is the bench's facts-only writer, never this answerer's.
            if ($version !== 5 && is_file($file = __DIR__.'/../Prompts/'.$name.'.v'.$version.'.md')) {
                return $file;
            }
        }

        return null;
    }

    /**
     * The JSON Schema a prompt's reply is held to, from the file beside it ({name}.v{n}.schema.json):
     * the provider then always returns JSON that parses. Null for a prompt without one.
     *
     * @return array<string, mixed>|null
     */
    public static function schemaFor(?string $promptFile): ?array
    {
        $file = $promptFile === null ? null : substr($promptFile, 0, -3).'.schema.json';
        $schema = $file !== null && is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($schema) ? $schema : null;
    }

    /** @return array{outcome: string, answer: string, from: string} */
    private function fixed(string $outcome, string $locale, string $from = 'none'): array
    {
        return ['outcome' => $outcome, 'answer' => (string) __('assistant::answers.site.'.$outcome, [], $locale), 'from' => $from];
    }
}
