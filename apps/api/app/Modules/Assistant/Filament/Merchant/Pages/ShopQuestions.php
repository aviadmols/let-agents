<?php

namespace App\Modules\Assistant\Filament\Merchant\Pages;

use App\Core\Facades\Settings;
use App\Core\Tenancy\LocksShopToPanelTenant;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Contracts\WritesPosts;
use App\Modules\Admin\Models\User;
use App\Modules\Assistant\Actions\AnswerSiteQuestion;
use App\Modules\Assistant\Actions\CheckAnswerChange;
use App\Modules\Assistant\Filament\Merchant\Concerns\EditsSourceLines;
use App\Modules\Assistant\Filament\Operator\Pages\ShopQuestions as OperatorShopQuestions;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Assistant\Models\AssistantAskReview;
use App\Modules\Assistant\Models\AssistantQuestionLink;
use App\Modules\Assistant\Models\AssistantSearchAsk;
use App\Modules\Assistant\Support\AnswerRules;
use App\Modules\Assistant\Support\Question;
use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Tenancy\Models\Shop;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * What shoppers asked, as the designer's panel lays it out: every question with the answer it got and
 * the score the daily review gave it, filtered by how it ended. A question without an answer can be
 * answered by the team (with a draft from a model, or in their own words), turned into a post, or set
 * aside as not relevant; once the team answered or set it aside, it leaves the list. The shop is fixed.
 */
final class ShopQuestions extends OperatorShopQuestions
{
    use EditsSourceLines;
    use LocksShopToPanelTenant {
        mount as lockShop;
    }

    public function mayFixLines(): bool
    {
        return $this->mayLink();
    }

    /** Opened from another screen with a question's "why" (?find=…&explain=1): its panel open on the first that matches. */
    public function mount(): void
    {
        $this->lockShop();

        if ($this->explain === '1' && trim($this->find) !== '') {
            $this->why = $this->visible($this->rows())[0]['id'] ?? null;
        }

        $this->explain = '';
    }

    private const DAYS = 30;

    private const PAGE = 30;

    protected static string|UnitEnum|null $navigationGroup = 'inbox';

    protected static ?int $navigationSort = 10;

    protected string $view = 'assistant::merchant.questions';

    /** The screen's name, as the menu says it; the tab says which page. */
    public function getTitle(): string
    {
        return __('admin::panels.screens.inbox');
    }

    /** all, open (no answer), answered, off (not about the store) */
    #[Url]
    public string $show = 'all';

    #[Url(as: 'find')]
    public string $find = '';

    /** need: what has no answer first, then the most asked; date: the latest asked first, by its date and hour */
    #[Url(as: 'order')]
    public string $order = 'need';

    public function orderBy(string $order): void
    {
        $this->order = $order === 'date' ? 'date' : 'need';
        $this->shown = self::PAGE;
    }

    /** The row being answered by hand. */
    public ?string $writing = null;

    public int $shown = self::PAGE;

    public function filter(string $show): void
    {
        $this->show = in_array($show, ['all', 'open', 'answered', 'off'], true) ? $show : 'all';
        $this->shown = self::PAGE;
    }

    public function more(): void
    {
        $this->shown += self::PAGE;
    }

    /**
     * "Reset questions": from this moment the list shows only what is asked after it, so the team sees
     * the answers after a round of changes alone. Nothing is deleted; the operator's screen keeps all.
     */
    public function resetQuestions(): void
    {
        if ($this->shop === null) {
            return;
        }

        Settings::set('assistant.questions_from', now()->toIso8601String(), $this->shop);
        $this->shown = self::PAGE;
        Notification::make()->title(__('assistant::ui.inbox.reset_done'))->success()->send();
    }

    /** The list starts at the last weeks, or at the reset when the team reset it since. */
    private function since(): Carbon
    {
        $since = now()->subDays(self::DAYS);
        $from = (string) Settings::get('assistant.questions_from', $this->shop);

        try {
            return $from !== '' && ($at = Carbon::parse($from))->gt($since) ? $at : $since;
        } catch (\Throwable) {
            return $since;
        }
    }

    /**
     * Every question of the last weeks, the same one once: from the search window (grouped by its words)
     * and from product and article pages, with its answer, score and how many asked. What the team
     * already answered or set aside is not here.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(): array
    {
        if ($this->shop === null) {
            return [];
        }

        // Once a request: the list, its counts, the side panel and each action read the same rows, and each reading was
        // the last weeks' questions again (up to 2,000), so the "i" took seconds to open.
        $memo = $this->order.'|'.$this->shop;
        if ($this->rowsRead !== null && $this->rowsRead[0] === $memo) {
            return $this->rowsRead[1];
        }

        $rows = $this->readRows();
        $this->rowsRead = [$memo, $rows];

        return $rows;
    }

    /** @var array{0: string, 1: list<array<string, mixed>>}|null the rows read in this request, by the order they were read in */
    private ?array $rowsRead = null;

    /** Read again once for the screen itself: an action of this request (an answer, a question set aside) changed them. */
    public function rendering(): void
    {
        $this->rowsRead = null;
    }

    /** @return list<array<string, mixed>> */
    private function readRows(): array
    {
        return app(TenantContext::class)->run($this->shop, function (): array {
            $since = $this->since();
            $handled = AssistantAnswer::query()->whereNull('product_id')->whereNull('content_id')
                ->where(fn ($q) => $q->where('source', self::TEAM)->orWhere('status', AssistantAnswer::HIDDEN))
                ->pluck('question_key')->flip();
            $scores = [];

            foreach (AssistantAskReview::query()->where('day', '>=', $since->toDateString())->orderBy('day')->get(['items']) as $review) {
                foreach ((array) $review->items as $item) {
                    $scores[(string) $item['ask_id']] = $item;
                }
            }

            $rows = [];
            // what the team linked each question to (its categories, tags, products), by the question's key
            $links = AssistantQuestionLink::query()->get()->keyBy('question_key');

            // The search window: each question once, with its latest answer and score.
            $asks = AssistantSearchAsk::query()->with('answer:id,answer,source,status')->where('created_at', '>=', $since)->latest('created_at')->orderByDesc('id')->limit(2000)->get();
            foreach ($asks->groupBy(fn (AssistantSearchAsk $a): string => Question::key((string) $a->question)) as $key => $group) {
                if (isset($handled[$key])) {
                    continue;
                }

                $latest = $group->first();
                $score = $group->map(fn (AssistantSearchAsk $a) => $scores[(string) $a->id] ?? null)->filter()->first();
                $rows[] = [
                    'id' => 's:'.$key,
                    // the latest ask, for the side panel to read it at once
                    'ask_id' => (string) $latest->id,
                    'question' => (string) $latest->question,
                    'where' => __('assistant::ui.inbox.in_search'),
                    'asked' => $group->count(),
                    'last' => $latest->created_at,
                    'state' => self::state((string) $latest->outcome),
                    // A saved answer (the relation), else what was shown that time (the column of the same name).
                    'answer' => trim((string) ($latest->getRelation('answer')?->getAttribute('answer') ?? $latest->getAttributes()['answer'] ?? '')),
                    'score' => $score === null ? null : (int) $score['score'],
                    'note' => $score['note'] ?? null,
                    // the products shown with the answer that time, those the shopper opened or added marked
                    'products' => self::shownWith($latest),
                    'link' => isset($links[$key]) && ! $links[$key]->empty() ? self::linkSummary($links[$key]) : null,
                ];
            }

            // Product and article pages.
            $answers = AssistantAnswer::query()->with(['product:id,title', 'content:id,title'])
                ->where(fn ($q) => $q->whereNotNull('product_id')->orWhereNotNull('content_id'))
                ->where('last_asked_at', '>=', $since)->where('status', AssistantAnswer::SHOWN)->where('source', '!=', self::TEAM)
                ->orderByDesc('last_asked_at')->limit(1000)->get();
            foreach ($answers as $answer) {
                $rows[] = [
                    'id' => 'a:'.$answer->id,
                    'question' => (string) $answer->question,
                    'where' => __('assistant::ui.inbox.on_page', ['page' => (string) ($answer->product?->title ?? $answer->content?->title ?? '')]),
                    'asked' => (int) $answer->asked_count,
                    'last' => $answer->last_asked_at,
                    'state' => self::state((string) $answer->outcome),
                    'answer' => trim((string) $answer->answer),
                    'score' => null,
                    'note' => null,
                    'products' => [],
                    'link' => null,
                ];
            }

            usort($rows, $this->order === 'date'
                ? fn (array $a, array $b): int => ($b['last']?->getTimestamp() ?? 0) <=> ($a['last']?->getTimestamp() ?? 0)
                : fn (array $a, array $b): int => [$b['state'] === 'open', $b['asked'], $b['last']?->getTimestamp() ?? 0] <=> [$a['state'] === 'open', $a['asked'], $a['last']?->getTimestamp() ?? 0]);

            return $rows;
        });
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{all: int, open: int, answered: int, off: int}
     */
    public function counts(array $rows): array
    {
        $by = array_count_values(array_column($rows, 'state'));

        return ['all' => count($rows), 'open' => $by['open'] ?? 0, 'answered' => $by['answered'] ?? 0, 'off' => $by['off'] ?? 0];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function visible(array $rows): array
    {
        $words = mb_strtolower(trim($this->find));

        return array_values(array_filter($rows, fn (array $r): bool => ($this->show === 'all' || $r['state'] === $this->show)
            && ($words === '' || str_contains(mb_strtolower($r['question'].' '.$r['answer']), $words))));
    }

    /** A draft from a model, from the store's own text: the team reads it, fixes it and publishes. */
    public function draftFor(string $row): void
    {
        $id = $this->answerFor($row);

        if ($id !== null) {
            $this->writing = $row;
            $this->draft($id);
            $this->drafts[$row] = $this->drafts[$id] ?? '';
            $this->draftChecks[$row] = $this->draftChecks[$id] ?? [];
        }
    }

    public function writeOwn(string $row): void
    {
        $this->writing = $row;
        $this->drafts[$row] ??= '';
    }

    /** The team's answer: every next shopper gets it, and it joins the knowledge; the row leaves the list. */
    public function publish(string $row): void
    {
        $id = $this->answerFor($row);

        if ($id === null) {
            return;
        }

        $this->drafts[$id] = (string) ($this->drafts[$row] ?? '');
        $this->answer($id);
        unset($this->drafts[$row], $this->draftChecks[$row]);
        $this->writing = null;
    }

    /** Not relevant: not suggested again, asked again it stays aside; the row leaves the list. */
    public function setAside(string $row): void
    {
        $id = $this->answerFor($row);

        if ($id !== null) {
            $this->hide($id);
        }
    }

    /** A dedicated post on the question, drafted now and waiting for approval in the suggestions. */
    public function post(string $row): void
    {
        $question = collect($this->rows())->firstWhere('id', $row)['question'] ?? null;

        if ($question === null || $this->shop === null || ! app()->bound(WritesPosts::class)) {
            return;
        }

        $said = app(WritesPosts::class)->postFromQuestion($this->shop, (string) $question);
        Notification::make()->{$said === 'writing' ? 'success' : 'warning'}()->title(__('assistant::ui.inbox.post_'.$said))->send();
    }

    public function canPost(): bool
    {
        return app()->bound(WritesPosts::class);
    }

    public static function ago(?Carbon $at): string
    {
        return $at === null ? '' : $at->locale(app()->getLocale())->diffForHumans();
    }

    // ------------------------------------------------------------------ linking a question to its products

    /** The row whose links are being chosen. */
    public ?string $linking = null;

    /** @var array{categories: list<string>, tags: list<string>, products: list<string>} what is chosen for it */
    public array $link = ['categories' => [], 'tags' => [], 'products' => []];

    /** Words to find a product to link by hand. */
    public string $productFind = '';

    /** The most categories and tags offered to link: the most used. */
    private const LINK_CHOICES = 60;

    public function startLink(string $row): void
    {
        $question = collect($this->rows())->firstWhere('id', $row)['question'] ?? null;
        if ($question === null || ! str_starts_with($row, 's:') || $this->shop === null) {
            return;
        }
        $have = app(TenantContext::class)->run($this->shop, fn () => AssistantQuestionLink::query()->where('question_key', Question::key((string) $question))->first());
        $this->link = [
            'categories' => array_values(array_map('strval', (array) ($have?->categories ?? []))),
            'tags' => array_values(array_map('strval', (array) ($have?->tags ?? []))),
            'products' => array_values(array_map('strval', (array) ($have?->products ?? []))),
        ];
        $this->productFind = '';
        $this->linking = $row;
    }

    /** Who may link a question to products: who edits the shop (a viewer only looks), or an operator. */
    public function mayLink(): bool
    {
        $user = auth()->user();
        $shop = $this->shop === null ? null : Shop::query()->find($this->shop);

        return $user instanceof User && $shop !== null && ($user->is_operator || $user->canEditShop($shop));
    }

    public function cancelLink(): void
    {
        $this->linking = null;
    }

    public function toggleLink(string $kind, string $value): void
    {
        if (! in_array($kind, ['categories', 'tags', 'products'], true)) {
            return;
        }
        $list = (array) ($this->link[$kind] ?? []);
        $this->link[$kind] = in_array($value, $list, true) ? array_values(array_diff($list, [$value])) : [...$list, $value];
    }

    /** Saved for the question and every question worded like it; nothing chosen takes the link away. */
    public function saveLink(): void
    {
        $question = collect($this->rows())->firstWhere('id', (string) $this->linking)['question'] ?? null;
        if ($question === null || $this->shop === null || ! $this->mayLink()) {
            return;
        }
        app(TenantContext::class)->run($this->shop, function () use ($question): void {
            $chosen = array_map(fn ($list): array => array_values(array_unique(array_filter(array_map('strval', (array) $list)))), $this->link);
            $key = Question::key((string) $question);
            if ($chosen['categories'] === [] && $chosen['tags'] === [] && $chosen['products'] === []) {
                AssistantQuestionLink::query()->where('question_key', $key)->delete();

                return;
            }
            AssistantQuestionLink::query()->updateOrCreate(['shop_id' => $this->shop, 'question_key' => $key], [
                'question' => mb_substr((string) $question, 0, 500), 'categories' => $chosen['categories'], 'tags' => $chosen['tags'],
                'products' => $chosen['products'], 'created_by' => auth()->id(),
            ]);
        });
        $this->linking = null;
        Notification::make()->success()->title(__('assistant::ui.inbox.link_saved'))->send();
    }

    /**
     * What a question can be linked to: the store's categories and tags, the most used first.
     *
     * @return array{categories: list<array{id: string, name: string, n: int}>, tags: list<array{name: string, n: int}>}
     */
    public function linkChoices(): array
    {
        if ($this->shop === null) {
            return ['categories' => [], 'tags' => []];
        }

        return app(TenantContext::class)->run($this->shop, function (): array {
            $counts = DB::table('catalog_category_product as cp')->join('catalog_products as p', 'p.id', '=', 'cp.product_id')
                ->where('p.shop_id', $this->shop)->whereNull('p.removed_at')
                ->select('cp.category_id')->selectRaw('count(*) as n')->groupBy('cp.category_id')->pluck('n', 'category_id');
            $categories = CatalogCategory::query()->whereIn('id', $counts->keys()->all())->get(['id', 'external_id', 'name'])
                ->map(fn (CatalogCategory $c): array => ['id' => (string) $c->external_id, 'name' => (string) $c->name, 'n' => (int) ($counts[$c->id] ?? 0)])
                ->sortByDesc('n')->take(self::LINK_CHOICES)->values()->all();
            $tags = [];
            foreach (CatalogProduct::query()->active()->get(['payload']) as $product) {
                foreach (array_unique(array_map(fn ($t): string => trim((string) $t), (array) ($product->payload['tags'] ?? []))) as $tag) {
                    if ($tag !== '' && mb_strlen($tag) <= 40) {
                        $tags[$tag] = ($tags[$tag] ?? 0) + 1;
                    }
                }
            }
            arsort($tags);

            return ['categories' => $categories, 'tags' => array_map(fn (string $t, int $n): array => ['name' => $t, 'n' => $n], array_keys(array_slice($tags, 0, self::LINK_CHOICES, true)), array_values(array_slice($tags, 0, self::LINK_CHOICES, true)))];
        });
    }

    /**
     * Products to link by hand: those chosen, then those whose title holds the words typed.
     *
     * @return list<array{id: string, title: string, image: string|null, chosen: bool}>
     */
    public function productChoices(): array
    {
        if ($this->shop === null) {
            return [];
        }

        return app(TenantContext::class)->run($this->shop, function (): array {
            $chosen = (array) ($this->link['products'] ?? []);
            $out = CatalogProduct::query()->active()->whereIn('external_id', $chosen)->get(['external_id', 'title', 'image_url'])
                ->map(fn (CatalogProduct $p): array => ['id' => (string) $p->external_id, 'title' => (string) $p->title, 'image' => $p->image_url, 'chosen' => true])->all();
            $words = trim($this->productFind);
            if (mb_strlen($words) >= 2) {
                $found = CatalogProduct::query()->active()->where('title', 'like', '%'.$words.'%')->whereNotIn('external_id', $chosen)->limit(8)->get(['external_id', 'title', 'image_url']);
                $out = [...$out, ...$found->map(fn (CatalogProduct $p): array => ['id' => (string) $p->external_id, 'title' => (string) $p->title, 'image' => $p->image_url, 'chosen' => false])->all()];
            }

            return $out;
        });
    }

    /**
     * A link in a line: the names of its categories and tags, and how many products by hand.
     *
     * @return array{names: list<string>, products: int}
     */
    private static function linkSummary(AssistantQuestionLink $link): array
    {
        $categories = CatalogCategory::query()->whereIn('external_id', (array) $link->categories)->pluck('name')->map(fn ($n): string => (string) $n)->all();

        return ['names' => [...$categories, ...array_map('strval', (array) $link->tags)], 'products' => count((array) $link->products)];
    }

    // ------------------------------------------------------------------ why this answer, and changing how the store answers

    #[Url(as: 'explain')]
    public string $explain = '';

    /** The row whose "why" is open. */
    public ?string $why = null;

    /** What is being changed in the open panel: a rule (new, or one by its id) or the tone; null when nothing. */
    public ?string $changing = null;

    /** @var array{text: string, replaces: string|null, style: string, note: string} the change as the team writes it */
    public array $change = ['text' => '', 'replaces' => null, 'style' => '', 'note' => ''];

    /** The check of the change, by its id (CheckAnswerChange), while it runs and after. */
    public ?string $checkId = null;

    /** @var array<string, string> the answer as it comes out after a change was kept, by row */
    public array $updated = [];

    public function explainRow(string $row): void
    {
        $this->why = $this->why === $row ? null : $row;
        $this->cancelChange();
    }

    /** The side panel closed. */
    public function closeWhy(): void
    {
        $this->why = null;
        $this->cancelChange();
        $this->cancelLine();
    }

    /** "Fix the answer" from the side panel: closed, and the answer's own box opened in its row. */
    public function fixFromWhy(string $row): void
    {
        $this->closeWhy();
        $this->writeOwn($row);
    }

    /** A new rule, or one of the store's rules to write again (by its id). */
    public function startRule(?string $ruleId = null): void
    {
        if (! $this->mayLink() || $this->shop === null) {
            return;
        }

        $rule = $ruleId === null ? null : collect(AnswerRules::rows($this->shop))->firstWhere('id', $ruleId);
        $this->change = ['text' => (string) ($rule['text'] ?? ''), 'replaces' => $rule === null ? null : (string) $rule['id'], 'style' => '', 'note' => ''];
        $this->changing = CheckAnswerChange::RULE;
        $this->checkId = null;
    }

    public function startTone(): void
    {
        if (! $this->mayLink() || $this->shop === null) {
            return;
        }

        $this->change = [
            'text' => '', 'replaces' => null,
            'style' => (string) (Settings::get('assistant.answer_tone', $this->shop) ?: AnswerSiteQuestion::TONE_DEFAULT),
            'note' => (string) Settings::get('assistant.answer_tone_note', $this->shop),
        ];
        $this->changing = CheckAnswerChange::TONE;
        $this->checkId = null;
    }

    public function cancelChange(): void
    {
        $this->changing = null;
        $this->checkId = null;
        $this->change = ['text' => '', 'replaces' => null, 'style' => '', 'note' => ''];
    }

    /** The change checked before it can be kept: code at once, then a reading and a few questions answered with it. */
    public function checkChange(): void
    {
        if ($this->changing === null || $this->shop === null || ! $this->mayLink()) {
            return;
        }

        $question = $this->why === null ? null : (collect($this->rows())->firstWhere('id', $this->why)['question'] ?? null);
        $change = $this->changing === CheckAnswerChange::TONE
            ? ['kind' => CheckAnswerChange::TONE, 'style' => $this->change['style'], 'note' => $this->change['note']]
            : ['kind' => CheckAnswerChange::RULE, 'text' => $this->change['text'], 'replaces' => $this->change['replaces']];
        $started = app(CheckAnswerChange::class)->start($this->shop, $change, str_starts_with((string) $this->why, 's:') ? $question : null, auth()->id());

        if (isset($started['refused'])) {
            $this->checkId = null;
            Notification::make()->warning()->title(__('assistant::ui.inbox.why.refused.'.$started['refused'], ['n' => (int) Settings::get('assistant.answer_rules_max', $this->shop)]))->send();

            return;
        }

        $this->checkId = $started['id'];
    }

    /**
     * The check of the change as it stands, for this shop.
     *
     * @return array<string, mixed>|null
     */
    public function checkState(): ?array
    {
        return $this->checkId === null || $this->shop === null ? null : app(CheckAnswerChange::class)->state($this->checkId, $this->shop);
    }

    /** Keeps a change that passed its check, and shows the answer to the open question as it comes out with it. */
    public function saveChange(): void
    {
        if ($this->checkId === null || $this->shop === null || ! $this->mayLink()) {
            return;
        }

        $state = $this->checkState();

        if (! app(CheckAnswerChange::class)->apply($this->checkId, $this->shop, auth()->id())) {
            Notification::make()->warning()->title(__('assistant::ui.inbox.why.not_passed'))->send();

            return;
        }

        $mine = collect((array) ($state['items'] ?? []))->firstWhere('mine', true);
        if ($this->why !== null && is_array($mine) && trim((string) $mine['after']) !== '') {
            $this->updated[$this->why] = (string) $mine['after'];
        }

        $this->changing = null;
        $this->checkId = null;
        Notification::make()->success()->title(__('assistant::ui.inbox.why.saved'))->send();
    }

    /** A rule taken away: fewer rules make no answer wrong, so it is not checked. */
    public function removeRule(string $ruleId): void
    {
        if ($this->shop !== null && $this->mayLink()) {
            app(CheckAnswerChange::class)->remove($this->shop, $ruleId);
            Notification::make()->success()->title(__('assistant::ui.inbox.why.removed'))->send();
        }
    }

    /**
     * Why a row's answer is what it is, in the team's words: what it was written from, how it was checked, and how the
     * store's assistant speaks (its tone and its rules). Never a model, a cost or a mechanism.
     *
     * @return array{from: string, sources: list<array{title: string, url: string|null}>, checked: string|null, tone: string, note: string, rules: list<array{id: string, text: string}>, search: bool, rules_live: bool}
     */
    public function whyFor(string $row): array
    {
        $out = ['answer' => '', 'from' => 'none', 'sources' => [], 'checked' => null, 'tone' => '', 'note' => '', 'rules' => [], 'search' => str_starts_with($row, 's:'), 'rules_live' => AnswerSiteQuestion::promptReads('answer_site', AnswerSiteQuestion::releasedVersion('answer_site'), 'rules')];

        if ($this->shop === null) {
            return $out;
        }

        $out['tone'] = (string) __('assistant::shop_settings.tone_options.'.((string) (Settings::get('assistant.answer_tone', $this->shop) ?: AnswerSiteQuestion::TONE_DEFAULT)));
        $out['note'] = trim((string) Settings::get('assistant.answer_tone_note', $this->shop));
        $out['rules'] = array_map(fn (array $r): array => ['id' => $r['id'], 'text' => $r['text']], AnswerRules::rows($this->shop));

        return app(TenantContext::class)->run($this->shop, function () use ($row, $out): array {
            $saved = null;
            $ask = null;

            if (str_starts_with($row, 'a:')) {
                $saved = AssistantAnswer::query()->find(substr($row, 2));
            } else {
                // the row's latest ask, by its id (the list read it already), never the weeks' questions read again
                $askId = collect($this->rows())->firstWhere('id', $row)['ask_id'] ?? null;
                $ask = $askId === null ? null : AssistantSearchAsk::query()->with('answer')->find($askId);
                $saved = $ask?->getRelation('answer');
            }

            if ($saved instanceof AssistantAnswer && $saved->source === self::TEAM) {
                $out['from'] = 'team';
            } elseif ($ask !== null && $ask->from === 'none' && $ask->outcome !== AssistantAnswer::ANSWERED) {
                $out['from'] = 'none';
            } else {
                $sources = array_values(array_filter((array) ($saved?->sources ?? []), 'is_array'));
                $out['sources'] = array_slice(array_map(fn (array $s): array => ['title' => trim((string) ($s['title'] ?? '')), 'url' => isset($s['url']) && is_string($s['url']) && $s['url'] !== '' ? $s['url'] : null], $sources), 0, 6);
                $out['sources'] = array_values(array_filter($out['sources'], fn (array $s): bool => $s['title'] !== ''));
                $answerText = trim((string) ($saved?->answer ?? ($ask === null ? '' : ($ask->getAttributes()['answer'] ?? ''))));
                $out['answer'] = $answerText;
                $kept = array_values(array_filter((array) (((array) ($saved?->flags ?? []))['quotes'] ?? []), 'is_array'));
                $out['sources'] = array_map(fn (array $s): array => $s + self::quoteFor($s, $kept, $answerText), $out['sources']);
                $answered = ($ask?->outcome ?? $saved?->outcome) === AssistantAnswer::ANSWERED;
                $out['from'] = ! $answered ? 'none' : ($out['sources'] !== [] ? 'pages' : ($saved !== null && $saved->product_id !== null ? 'product' : 'store'));
            }

            // how it was checked, in plain words: the check of that answer, or a saved answer shown again
            $verdict = $ask?->verdict;
            $out['checked'] = match (true) {
                $out['from'] === 'team' => 'team',
                $ask !== null && $ask->from === 'bank' => 'saved',
                is_string($verdict) && str_starts_with($verdict, 'skipped') => null,
                in_array($verdict, ['kept', 'answer_removed', 'picks_removed', 'rejected'], true) => $verdict,
                default => null,
            };

            return $out;
        });
    }

    /**
     * What a source says that the answer came from: the words the answer was written from, as kept with it ("kept"); for
     * an answer kept before those were, the lines of that page as it reads now that share the most with the answer
     * ("now"), its numbers counted twice (a sum, an hour, a share are the details a shopper checks). Code only.
     *
     * @param  array{title: string, url: string|null}  $source
     * @param  list<array<string, mixed>>  $kept
     * @return array{quote: string, quote_kind: string}|array{}
     */
    private static function quoteFor(array $source, array $kept, string $answer): array
    {
        foreach ($kept as $quote) {
            if ((string) ($quote['title'] ?? '') === $source['title'] || ($source['url'] !== null && (string) ($quote['url'] ?? '') === $source['url'])) {
                $text = trim((string) ($quote['text'] ?? ''));

                return $text === '' ? [] : ['quote' => $text, 'quote_kind' => 'kept'];
            }
        }

        if ($answer === '') {
            return [];
        }

        $page = CatalogContent::query()->active()
            ->where(fn ($q) => $source['url'] !== null ? $q->where('url', $source['url']) : $q->where('title', $source['title']))
            ->first(['body', 'excerpt']);
        $text = trim((string) ($page?->body ?: $page?->excerpt ?? ''));

        if ($text === '') {
            return [];
        }

        $words = array_values(array_unique(array_filter(preg_split('/[^\p{L}\p{N}%.:]+/u', mb_strtolower($answer)) ?: [], fn (string $w): bool => mb_strlen(trim($w, '.:')) >= 3 || preg_match('/\d/', $w) === 1)));
        $lines = preg_split('/(?<=[.!?])\s+|\n+/u', $text) ?: [];
        $scored = [];

        foreach ($lines as $i => $line) {
            $line = trim($line);
            $low = mb_strtolower($line);
            $score = 0;
            foreach ($words as $word) {
                $word = trim($word, '.:');
                if ($word !== '' && str_contains($low, $word)) {
                    $score += preg_match('/\d/', $word) === 1 ? 2 : 1;
                }
            }
            if ($line !== '' && $score >= 2) {
                $scored[$i] = $score;
            }
        }

        if ($scored === []) {
            return [];
        }

        // the three lines that share the most, in the order the page has them
        arsort($scored);
        $best = array_slice(array_keys($scored), 0, 3);
        sort($best);

        return ['quote' => AnswerSiteQuestion::quoteOf(implode(' ', array_map(fn (int $i): string => trim($lines[$i]), $best))), 'quote_kind' => 'now'];
    }

    /** When it was asked, to the minute, as the shop reads the time: "28.10.2026 11:39". */
    public static function at(?Carbon $at): string
    {
        return $at === null ? '' : $at->copy()->timezone((string) config('app.display_timezone', 'Asia/Jerusalem'))->format('d.m.Y H:i');
    }

    /**
     * The products the answer showed the shopper, as the store has them now: its picks first (with why), then the others
     * it showed (named in its words, or a set it listed); each marked when the shopper opened or added it. Never the
     * results that were on screen before the question: those were not the answer's.
     *
     * @return list<array{title: string, url: string|null, image: string|null, price: string|null, why: string|null, opened: bool}>
     */
    private static function shownWith(AssistantSearchAsk $ask): array
    {
        $opened = array_flip(array_map('strval', (array) $ask->picked));
        $picks = array_values(array_filter((array) $ask->picks, 'is_array'));
        $why = array_column($picks, 'why', 'external_id');
        $ids = [...array_map(fn (array $p): string => (string) ($p['external_id'] ?? ''), $picks), ...array_map('strval', (array) ($ask->shown ?? []))];
        $ids = array_slice(array_values(array_unique(array_filter($ids, fn (string $id): bool => $id !== ''))), 0, self::SHOWN_MAX);
        if ($ids === []) {
            return [];
        }
        $products = CatalogProduct::query()->whereIn('external_id', $ids)->get(['external_id', 'title', 'url', 'image_url', 'price', 'currency'])->keyBy('external_id');
        $titles = array_column($picks, 'title', 'external_id');

        return array_values(array_filter(array_map(function (string $id) use ($products, $titles, $why, $opened): array {
            $product = $products[$id] ?? null;

            return [
                'title' => trim((string) ($product->title ?? $titles[$id] ?? '')),
                'url' => $product?->url,
                'image' => $product?->image_url,
                'price' => $product?->price === null ? null : self::money((float) $product->price, (string) ($product->currency ?? '')),
                'why' => isset($why[$id]) && trim((string) $why[$id]) !== '' ? trim((string) $why[$id]) : null,
                'opened' => isset($opened[$id]),
            ];
        }, $ids), fn (array $p): bool => $p['title'] !== ''));
    }

    /** The most products shown with one question in the list. */
    private const SHOWN_MAX = 12;

    private static function money(float $amount, string $currency): string
    {
        $symbol = ['ILS' => '₪', 'USD' => '$', 'EUR' => '€', 'GBP' => '£'][strtoupper($currency)] ?? '';

        return $symbol.number_format($amount, $amount >= 100 || floor($amount) === $amount ? 0 : 2);
    }

    /**
     * The saved answer a row's actions work on: the page's own, or, for a question from the search
     * window, one made for it (no page, the question's key), so the team's answer is found again.
     */
    private function answerFor(string $row): ?string
    {
        if ($this->shop === null) {
            return null;
        }

        if (str_starts_with($row, 'a:')) {
            return substr($row, 2);
        }

        $key = substr($row, 2);
        $question = collect($this->rows())->firstWhere('id', $row);

        if ($question === null) {
            return null;
        }

        return app(TenantContext::class)->run($this->shop, fn (): string => (string) AssistantAnswer::query()->firstOrCreate(
            ['question_key' => $key, 'product_id' => null, 'content_id' => null],
            [
                'shop_id' => $this->shop, 'question' => mb_substr((string) $question['question'], 0, 500), 'outcome' => AssistantAnswer::NO_INFO,
                'source' => 'store', 'status' => AssistantAnswer::SHOWN, 'asked_count' => (int) $question['asked'],
                'last_asked_at' => $question['last'] ?? now(), 'prompt_version' => 1,
            ],
        )->id);
    }

    private static function state(string $outcome): string
    {
        return match ($outcome) {
            AssistantAnswer::ANSWERED => 'answered',
            AssistantAnswer::OUT_OF_SCOPE => 'off',
            default => 'open',
        };
    }
}
