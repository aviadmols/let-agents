<?php

namespace App\Modules\Assistant\Actions;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Runs\Models\Run;
use App\Modules\Tenancy\Models\Shop;

/**
 * The questions about the store a shopper would ask, answered before anyone asks: so a store connected
 * today shows useful questions at once, each with its answer ready, instead of waiting for shoppers.
 *
 * Which questions, in code, from what the store's own pages say (its pages, its policies, what its
 * product pages repeat): a topic is asked only where a page talks about it (delivery, returns, a
 * warranty, payments, …), and a page of frequently asked questions gives its own questions as written.
 * Each is answered by AnswerSiteQuestion::prepareForStore: the store's pages, one family writes, the other
 * checks; kept with its sources and shown to every shopper without a model, only when it passed clean.
 * A question is asked again only when a page it was answered from changes. After every sync, the first
 * included, once what the pages repeat was read (BuildShopKnowledgeJob).
 */
final class PrepareStoreAnswers
{
    public const AGENT = 'assistant.answer_preparer';

    public const ACTION = 'assistant.prepare_answers';

    /**
     * Topics a store's pages talk about, by the words that say so (Hebrew final letters read as regular),
     * each with its question in assistant::prepared. Delivery and returns are the questions a product
     * page offers (BuildPageBank), word for word, so one answer serves both.
     */
    public const TOPICS = [
        'delivery' => ['משלוח', 'אספקה', 'shipping', 'delivery'],
        'returns' => ['החזר', 'החלפ', 'refund', 'return'],
        'cancel' => ['ביטול עסקה', 'ביטול הזמנה', 'לבטל עסקה', 'cancel'],
        'warranty' => ['אחריות', 'warranty'],
        'payments' => ['תשלומים', 'installment'],
        'free_shipping' => ['משלוח חינם', 'free shipping'],
        'pickup' => ['איסוף עצמי', 'pickup', 'pick up'],
        'hours' => ['שעות פתיחה', 'שעות פעילות', 'opening hours'],
        'resize' => ['הגדלה', 'הקטנה', 'התאמת מידה', 'resiz'],
    ];

    /** A page of frequently asked questions, by its title. */
    private const FAQ_PAGE = '/שאלות נפוצות|שאלות ותשובות|\bfaq\b/iu';

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
        private readonly AnswerSiteQuestion $answers,
    ) {}

    public function handle(string $shopId, RunTrigger $trigger = RunTrigger::System): ?Run
    {
        if (! Features::enabled('assistant.prepare_answers', $shopId) || ! Features::enabled('assistant.on_search', $shopId)) {
            return null;
        }

        return $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            trigger: $trigger,
            work: fn (RunContext $run) => $this->tenant->run($shopId, fn () => $this->prepare($run, $shopId)),
        );
    }

    private function prepare(RunContext $run, string $shopId): void
    {
        $locale = (string) (Shop::query()->whereKey($shopId)->value('content_locale') ?: 'he');
        $questions = array_slice($this->questions($locale), 0, (int) Settings::get('assistant.prepared_per_sync', $shopId));
        $counts = ['questions' => count($questions), 'skipped' => 0, 'answered' => 0, 'not_answered' => 0, 'unavailable' => 0];
        $answered = [];

        foreach ($questions as $question) {
            $outcome = $this->answers->prepareForStore($shopId, $question, $locale);
            $counts[$outcome]++;
            if ($outcome === 'answered') {
                $answered[] = $question;
            }
            // The model is out of reach or the allowance spent: the rest wait for the next sync.
            if ($outcome === 'unavailable') {
                break;
            }
        }

        $run->output($counts + ['answered_questions' => $answered])->summary('assistant::runs.prepared', [
            'questions' => (string) $counts['questions'],
            'answered' => (string) $counts['answered'],
            'skipped' => (string) $counts['skipped'],
        ]);
    }

    /**
     * The store's own questions: a topic its pages talk about, then the questions its FAQ pages ask.
     *
     * @return list<string>
     */
    public function questions(string $locale): array
    {
        $pages = CatalogContent::query()->active()->whereIn('type', ['page', CatalogContent::KNOWLEDGE])->orderBy('id')->get(['title', 'body']);
        $plain = fn (string $text): string => strtr(mb_strtolower($text), ['ך' => 'כ', 'ם' => 'מ', 'ן' => 'נ', 'ף' => 'פ', 'ץ' => 'צ']);
        $text = $plain($pages->map(fn (CatalogContent $p): string => $p->title."\n".$p->body)->implode("\n"));

        $out = [];
        foreach (self::TOPICS as $topic => $words) {
            foreach ($words as $word) {
                if (preg_match('/(?<![\p{L}])[והבלש]?'.preg_quote($plain($word), '/').'/u', $text) === 1) {
                    $out[] = (string) __('assistant::prepared.'.$topic, [], $locale);
                    break;
                }
            }
        }

        foreach ($pages->filter(fn (CatalogContent $p): bool => preg_match(self::FAQ_PAGE, (string) $p->title) === 1) as $faq) {
            foreach (preg_split('/\n+/u', (string) $faq->body) ?: [] as $line) {
                $line = trim((string) preg_replace('/\s+/u', ' ', $line));
                if (str_ends_with($line, '?') && mb_strlen($line) >= 8 && mb_strlen($line) <= 140) {
                    $out[] = $line;
                }
            }
        }

        return array_values(array_unique($out));
    }
}
