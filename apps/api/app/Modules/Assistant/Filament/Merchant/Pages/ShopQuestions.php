<?php

namespace App\Modules\Assistant\Filament\Merchant\Pages;

use App\Core\Tenancy\LocksShopToPanelTenant;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Contracts\WritesPosts;
use App\Modules\Admin\Models\User;
use App\Modules\Assistant\Filament\Operator\Pages\ShopQuestions as OperatorShopQuestions;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Assistant\Models\AssistantAskReview;
use App\Modules\Assistant\Models\AssistantQuestionLink;
use App\Modules\Assistant\Models\AssistantSearchAsk;
use App\Modules\Assistant\Support\Question;
use App\Modules\Catalog\Models\CatalogCategory;
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
    use LocksShopToPanelTenant;

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

        return app(TenantContext::class)->run($this->shop, function (): array {
            $since = now()->subDays(self::DAYS);
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
