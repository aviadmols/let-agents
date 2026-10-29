<?php

namespace App\Modules\Assistant\Filament\Operator\Pages;

use App\Core\Tenancy\TenantContext;
use App\Modules\Assistant\Actions\BuildShopKnowledge;
use App\Modules\Assistant\Jobs\CheckOwnKnowledgeJob;
use App\Modules\Assistant\Jobs\ReadPostKnowledgeJob;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Assistant\Support\RepeatedText;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Enrichment\Contracts\ShopRules;
use App\Modules\Tenancy\Models\Shop;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * What the assistant knows about the store before it reads any page: what the product pages
 * repeat, the store's policies, what its posts say about it, what the team wrote, and the team's
 * answers. The team sees each part, removes a sentence that is not the store's, and adds what the
 * site does not say.
 */
class AboutStore extends Page
{
    /** Words in a page title that make it one of the store's policies. */
    private const POLICY_WORDS = '/מדיניות|החזר|ביטול|משלוח|אחריות|תקנון|פרטיות|החלפ|policy|refund|return|shipping|terms|warranty|privacy/iu';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string|UnitEnum|null $navigationGroup = 'discovery';

    protected static ?int $navigationSort = 12;

    protected static ?string $slug = 'about-store';

    protected string $view = 'assistant::operator.about-store';

    #[Url]
    public ?string $shop = null;

    /** What the team writes about the store. */
    public string $own = '';

    /** The knowledge centre's tab: general, posts, found. */
    #[Url]
    public string $tab = 'general';

    /** A line the team is adding. */
    public string $newInfo = '';

    /** A rule of the store's products, in the team's words (Enrichment\Contracts\ShopRules). */
    public string $newRule = '';

    /** @var list<array{type: string, id: string, label: string}> what the rule being written names with "@" */
    public array $ruleMentions = [];

    /** The line being edited, and its text. */
    public ?string $editing = null;

    public string $editText = '';

    /** A post's address, to read it now. */
    public string $postUrl = '';

    /** The post whose facts are open. */
    public ?string $openPost = null;

    /** How many rows each tab shows; "load more" adds. */
    public array $limits = ['general' => 10, 'posts' => 10, 'found' => 10, 'rules' => 10];

    private const TABS = ['general', 'posts', 'found', 'rules'];

    private const MORE = 20;

    public static function getNavigationLabel(): string
    {
        return __('assistant::ui.knowledge.title');
    }

    public function getTitle(): string
    {
        return __('assistant::ui.knowledge.title');
    }

    public function getSubheading(): ?string
    {
        return __('assistant::ui.knowledge.subheading');
    }

    /** False in the merchant panel, where the shop is the one in the address. */
    public function picksShop(): bool
    {
        return true;
    }

    /** @return array<string, string> */
    public function shops(): array
    {
        return Shop::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    public function mount(): void
    {
        $this->shop ??= app(TenantContext::class)->id() ?? Shop::query()->orderBy('name')->value('id');
        $this->load();
    }

    public function updatedShop(): void
    {
        $this->load();
    }

    public function load(): void
    {
        // The lines the team added, one under the other, as the operator's box edits them.
        $this->own = $this->shop === null ? '' : implode('
', array_column($this->inShop(fn (): array => app(BuildShopKnowledge::class)->ownRows()), 'text'));
    }

    /**
     * Each part, ready for the screen.
     *
     * @return array{repeated: list<string>, read_at: string|null, pages: int, policies: list<array{title: string, url: string|null}>, posts: list<array{fact: string, title: string}>, team: list<array{question: string, answer: string}>}|null
     */
    public function knowledge(): ?array
    {
        if ($this->shop === null) {
            return null;
        }

        return $this->inShop(function (): array {
            $entries = app(BuildShopKnowledge::class)->entries();
            $repeated = $entries[BuildShopKnowledge::REPEATED] ?? null;
            $team = $entries[BuildShopKnowledge::TEAM] ?? null;
            $posts = $entries[BuildShopKnowledge::POSTS] ?? null;
            $hidden = (array) ($posts?->terms['hidden'] ?? []);

            return [
                'repeated' => $repeated === null || $repeated->removed_at !== null ? [] : array_values(array_filter(array_map(
                    fn (string $line): string => trim((string) preg_replace('/^-\s*/u', '', $line)),
                    explode("\n", (string) $repeated->body),
                ))),
                'read_at' => $repeated?->terms['read_at'] ?? null,
                'pages' => (int) ($repeated?->terms['pages'] ?? 0),
                'policies' => CatalogContent::query()->active()->where('type', CatalogContent::PAGE)->orderBy('title')->get(['title', 'url'])
                    ->filter(fn (CatalogContent $page): bool => preg_match(self::POLICY_WORDS, (string) $page->title) === 1)
                    ->map(fn (CatalogContent $page): array => ['title' => (string) $page->title, 'url' => $page->url])
                    ->values()->all(),
                'posts' => collect((array) ($posts?->terms['read'] ?? []))
                    ->flatMap(fn (array $post): array => array_map(fn (string $fact): array => ['fact' => $fact, 'title' => (string) $post['title']], (array) $post['facts']))
                    ->reject(fn (array $line): bool => in_array(RepeatedText::key($line['fact']), $hidden, true))
                    ->values()->all(),
                'team' => $team === null || $team->removed_at !== null ? [] : array_values(array_filter(array_map(
                    function (string $pair): ?array {
                        $lines = explode("\n", trim($pair), 2);

                        return count($lines) === 2 ? ['question' => self::unlabel($lines[0]), 'answer' => self::unlabel($lines[1])] : null;
                    },
                    preg_split('/\n{2,}/u', (string) $team->body) ?: [],
                ))),
            ];
        });
    }

    public function saveOwn(): void
    {
        if ($this->shop === null) {
            return;
        }

        $this->inShop(fn () => app(BuildShopKnowledge::class)->own(mb_substr($this->own, 0, 6000)));
        Notification::make()->success()->title(__('assistant::ui.knowledge.saved'))->send();
        $this->load();
    }

    public function remove(string $sentence): void
    {
        if ($this->shop === null) {
            return;
        }

        $this->inShop(fn () => app(BuildShopKnowledge::class)->hide($sentence));
        Notification::make()->success()->title(__('assistant::ui.knowledge.removed'))->send();
    }

    public function removePostFact(string $fact): void
    {
        if ($this->shop === null) {
            return;
        }

        $this->inShop(fn () => app(BuildShopKnowledge::class)->hidePostFact($fact));
        Notification::make()->success()->title(__('assistant::ui.knowledge.removed'))->send();
    }

    /** The posts read now, in the background: a model call or two a post. */
    public function readPosts(): void
    {
        if ($this->shop === null) {
            return;
        }

        ReadPostKnowledgeJob::dispatch((string) $this->shop);
        Notification::make()->success()->title(__('assistant::ui.knowledge.posts.reading'))->send();
    }

    public function readAgain(): void
    {
        if ($this->shop === null) {
            return;
        }

        // A dozen pages, read now, so the team sees at once what was found or why nothing was.
        $read = $this->inShop(fn (): array => app(BuildShopKnowledge::class)->repeated((string) $this->shop));

        if ($read['pages'] < 3) {
            Notification::make()->warning()->title(__('assistant::ui.knowledge.unread'))->body(__('assistant::ui.knowledge.unread_body', ['tried' => $read['tried'], 'pages' => $read['pages']]))->send();

            return;
        }

        Notification::make()->success()->title(__('assistant::ui.knowledge.read_now', ['pages' => $read['pages'], 'sentences' => $read['sentences']]))->send();
    }

    public function show(string $tab): void
    {
        $this->tab = in_array($tab, self::TABS, true) ? $tab : 'general';
    }

    public function more(string $tab): void
    {
        if (in_array($tab, self::TABS, true)) {
            $this->limits[$tab] = (int) ($this->limits[$tab] ?? 10) + self::MORE;
        }
    }

    /** A line the team adds: it joins "general information" at once, and is checked against the site in the background. */
    public function addInfo(): void
    {
        $text = trim($this->newInfo);

        if ($this->shop === null || $text === '') {
            return;
        }

        $id = $this->inShop(fn (): ?string => app(BuildShopKnowledge::class)->addOwn($text));

        if ($id !== null) {
            CheckOwnKnowledgeJob::dispatch((string) $this->shop, $id);
        }

        $this->newInfo = '';
        $this->tab = 'general';
        Notification::make()->success()->title(__('assistant::ui.centre.added'))->body(__('assistant::ui.centre.added_body'))->send();
    }

    public function startEdit(string $id): void
    {
        $row = collect($this->ownRows())->firstWhere('id', $id);
        $this->editing = $row === null ? null : $id;
        $this->editText = (string) ($row['text'] ?? '');
    }

    public function saveEdit(): void
    {
        if ($this->shop === null || $this->editing === null) {
            return;
        }

        $id = $this->editing;
        $this->inShop(fn () => app(BuildShopKnowledge::class)->editOwn($id, $this->editText));

        if (trim($this->editText) !== '') {
            CheckOwnKnowledgeJob::dispatch((string) $this->shop, $id);
        }

        $this->editing = null;
        $this->editText = '';
        Notification::make()->success()->title(__('assistant::ui.knowledge.saved'))->send();
    }

    public function cancelEdit(): void
    {
        $this->editing = null;
        $this->editText = '';
    }

    public function removeInfo(string $id): void
    {
        if ($this->shop !== null) {
            $this->inShop(fn () => app(BuildShopKnowledge::class)->removeOwn($id));
            Notification::make()->success()->title(__('assistant::ui.knowledge.removed'))->send();
        }
    }

    /** @return list<array{id: string, text: string, at: string|null, check: array<string, mixed>|null}> */
    public function ownRows(): array
    {
        return $this->shop === null ? [] : $this->inShop(fn (): array => app(BuildShopKnowledge::class)->ownRows());
    }

    /**
     * The site's pages, as the assistant knows them: a line of what each says, how many questions
     * it answered, and whether it was read. Policies first.
     *
     * @return list<array{id: string, title: string, url: string|null, summary: string, answered: int, read: bool}>
     */
    public function pages(): array
    {
        if ($this->shop === null) {
            return [];
        }

        return $this->inShop(function (): array {
            $read = (array) (app(BuildShopKnowledge::class)->entries()[BuildShopKnowledge::POSTS]?->terms['read'] ?? []);
            $pages = CatalogContent::query()->active()->where('type', CatalogContent::PAGE)->get(['id', 'title', 'url', 'excerpt', 'body', 'external_id', 'type'])
                ->sortBy(fn (CatalogContent $page): array => [$page->importance(), mb_strtolower((string) $page->title)])->values();
            $answered = $this->answeredBy($pages);

            return $pages->map(fn (CatalogContent $page): array => [
                'id' => (string) $page->id,
                'title' => trim((string) $page->title),
                'url' => $page->url,
                'summary' => self::line((string) (($read[(string) $page->id]['facts'] ?? [])[0] ?? $page->excerpt ?? strip_tags((string) $page->body))),
                'answered' => $answered[(string) $page->id] ?? 0,
                'read' => isset($read[(string) $page->id]),
            ])->all();
        });
    }

    /**
     * The site's posts, newest first, with what was learned from each and when it was read.
     *
     * @return list<array{id: string, title: string, url: string|null, facts: list<string>, read_at: string|null, read: bool}>
     */
    public function postRows(): array
    {
        if ($this->shop === null) {
            return [];
        }

        return $this->inShop(function (): array {
            $entry = app(BuildShopKnowledge::class)->entries()[BuildShopKnowledge::POSTS] ?? null;
            $read = (array) ($entry?->terms['read'] ?? []);
            $hidden = (array) ($entry?->terms['hidden'] ?? []);
            $wanted = (array) ($entry?->terms['wanted'] ?? []);

            return CatalogContent::query()->active()->where('type', CatalogContent::POST)->orderByDesc('source_updated_at')->orderByDesc('id')
                ->get(['id', 'title', 'url'])
                ->map(function (CatalogContent $post) use ($read, $hidden, $wanted, $entry): array {
                    $known = $read[(string) $post->id] ?? null;

                    return [
                        'id' => (string) $post->id,
                        'title' => trim((string) $post->title),
                        'url' => $post->url,
                        'facts' => array_values(array_filter((array) ($known['facts'] ?? []), fn (string $fact): bool => ! in_array(RepeatedText::key($fact), $hidden, true))),
                        'read_at' => $known === null ? null : ($known['read_at'] ?? $entry?->terms['read_at'] ?? null),
                        'read' => $known !== null,
                        'waiting' => in_array((string) $post->id, $wanted, true),
                    ];
                })->all();
        });
    }

    public function togglePost(string $id): void
    {
        $this->openPost = $this->openPost === $id ? null : $id;
    }

    /** A post's address: read at the next reading, first, and that reading starts now. */
    public function addPost(): void
    {
        $url = trim($this->postUrl);

        if ($this->shop === null || $url === '') {
            return;
        }

        $key = self::urlKey($url);
        $found = $this->inShop(fn (): ?CatalogContent => CatalogContent::query()->active()->whereIn('type', [CatalogContent::POST, CatalogContent::PAGE])->whereNotNull('url')->get(['id', 'url', 'title'])
            ->first(fn (CatalogContent $c): bool => self::urlKey((string) $c->url) === $key));

        if ($found === null) {
            Notification::make()->warning()->title(__('assistant::ui.centre.post_unknown'))->body(__('assistant::ui.centre.post_unknown_body'))->send();

            return;
        }

        $this->inShop(fn () => app(BuildShopKnowledge::class)->want((string) $found->id));
        ReadPostKnowledgeJob::dispatch((string) $this->shop);
        $this->postUrl = '';
        $this->tab = 'posts';
        Notification::make()->success()->title(__('assistant::ui.centre.post_reading', ['title' => (string) $found->title]))->send();
    }

    /**
     * What was found on the site: what product pages repeat (on about how many), what posts say
     * about the store, and the team's answers. Each can be removed and is not read again.
     *
     * @return list<array{kind: string, key: string, text: string, from: string}>
     */
    public function found(): array
    {
        $knowledge = $this->knowledge();

        if ($knowledge === null) {
            return [];
        }

        $rows = [];
        $repeated = $this->inShop(fn (): ?CatalogContent => app(BuildShopKnowledge::class)->entries()[BuildShopKnowledge::REPEATED] ?? null);
        $counts = (array) ($repeated?->terms['counts'] ?? []);
        $sampled = max(1, (int) ($repeated?->terms['pages'] ?? 0));
        $products = (int) ($repeated?->terms['products'] ?? 0);

        foreach ($knowledge['repeated'] as $sentence) {
            $on = (int) ($counts[RepeatedText::key($sentence)] ?? 0);
            $rows[] = [
                'kind' => 'repeated',
                'key' => $sentence,
                'text' => $sentence,
                'from' => $on > 0 && $products > 0
                    ? __('assistant::ui.centre.found_on', ['n' => number_format((int) round($products * min(1, $on / $sampled)))])
                    : __('assistant::ui.centre.found_on_most'),
            ];
        }

        foreach ($knowledge['posts'] as $line) {
            $rows[] = ['kind' => 'post', 'key' => $line['fact'], 'text' => $line['fact'], 'from' => __('assistant::ui.centre.found_post', ['title' => $line['title']])];
        }

        foreach ($this->teamAnswers() as $answer) {
            $rows[] = ['kind' => 'team', 'key' => $answer['id'], 'text' => $answer['answer'], 'from' => __('assistant::ui.centre.found_team', ['question' => $answer['question'], 'date' => $answer['date']])];
        }

        return $rows;
    }

    /** One line found on the site, removed: not read, said or used again. */
    public function removeFound(string $kind, string $key): void
    {
        if ($this->shop === null) {
            return;
        }

        match ($kind) {
            'repeated' => $this->remove($key),
            'post' => $this->removePostFact($key),
            'team' => $this->inShop(function () use ($key): void {
                AssistantAnswer::query()->whereKey($key)->where('source', 'team')->update(['status' => AssistantAnswer::HIDDEN]);
                app(BuildShopKnowledge::class)->team();
                Notification::make()->success()->title(__('assistant::ui.knowledge.removed'))->send();
            }),
            default => null,
        };
    }

    /** @return array{general: int, posts: int, found: int} what each tab holds */
    public function counts(): array
    {
        return ['general' => count($this->pages()), 'posts' => count($this->postRows()), 'found' => count($this->found()), 'rules' => count($this->rules())];
    }

    /**
     * The rules the team wrote of the store's products, each with what was understood and the products it changes,
     * where a module reads them (Enrichment\Contracts\ShopRules). Empty otherwise.
     *
     * @return list<array<string, mixed>>
     */
    public function rules(): array
    {
        if ($this->shop === null || ! app()->bound(ShopRules::class)) {
            return [];
        }

        return $this->inShop(fn (): array => app(ShopRules::class)->list());
    }

    /** A rule written: kept, and read in the background into a plan for the team to approve. */
    public function addRule(): void
    {
        $text = trim($this->newRule);

        if ($this->shop === null || $text === '' || ! app()->bound(ShopRules::class)) {
            return;
        }

        $id = $this->inShop(fn (): ?string => app(ShopRules::class)->add($text, auth()->id(), $this->ruleMentions));

        if ($id === null) {
            return;
        }

        $this->newRule = '';
        $this->ruleMentions = [];
        $this->tab = 'rules';
        Notification::make()->success()->title(__('assistant::ui.centre.rules.added'))->body(__('assistant::ui.centre.rules.added_body'))->send();
    }

    /**
     * What "@" and the words after it may name in a rule: the store's products as its own search finds them, its
     * categories, posts and pages, each with the word for its kind.
     *
     * @return list<array{type: string, id: string, label: string, sub: string|null, image: string|null, kind: string}>
     */
    public function mentionSearch(string $query): array
    {
        if ($this->shop === null || ! app()->bound(ShopRules::class)) {
            return [];
        }

        return array_map(fn (array $m): array => $m + ['kind' => __('assistant::ui.centre.rules.kinds.'.$m['type'])],
            $this->inShop(fn (): array => app(ShopRules::class)->mentionables($query)));
    }

    /** One chosen after "@": kept with the rule being written. */
    public function addMention(string $type, string $id, string $label): void
    {
        $this->ruleMentions = array_values(array_filter($this->ruleMentions, fn (array $m): bool => ! ($m['type'] === $type && $m['id'] === $id)));
        $this->ruleMentions[] = ['type' => mb_substr($type, 0, 20), 'id' => mb_substr($id, 0, 64), 'label' => mb_substr($label, 0, 200)];
    }

    /** The team's word on a rule's plan: applied, or rejected. Nothing changes before it. */
    public function decideRule(string $id, bool $approve): void
    {
        if ($this->shop === null || ! app()->bound(ShopRules::class)) {
            return;
        }

        $this->inShop(fn () => app(ShopRules::class)->decide($id, $approve, auth()->id()));
        Notification::make()->success()->title(__($approve ? 'assistant::ui.centre.rules.applied' : 'assistant::ui.centre.rules.rejected'))->send();
    }

    /** A rule that failed or came back unclear, read again. */
    public function retryRule(string $id): void
    {
        if ($this->shop === null || ! app()->bound(ShopRules::class)) {
            return;
        }

        $this->inShop(fn () => app(ShopRules::class)->retry($id));
        Notification::make()->success()->title(__('assistant::ui.centre.rules.added'))->body(__('assistant::ui.centre.rules.added_body'))->send();
    }

    public function removeRule(string $id): void
    {
        if ($this->shop === null || ! app()->bound(ShopRules::class)) {
            return;
        }

        $this->inShop(fn () => app(ShopRules::class)->remove($id));
    }

    /** @return list<array{id: string, question: string, answer: string, date: string}> */
    private function teamAnswers(): array
    {
        return $this->inShop(fn (): array => AssistantAnswer::query()
            ->whereNull('product_id')->whereNull('content_id')
            ->where('source', 'team')->where('status', AssistantAnswer::SHOWN)->whereNotNull('answer')
            ->orderByDesc('updated_at')->orderBy('id')->limit(200)
            ->get(['id', 'question', 'answer', 'updated_at'])
            ->map(fn (AssistantAnswer $a): array => [
                'id' => (string) $a->id,
                'question' => trim((string) $a->question),
                'answer' => trim((string) $a->answer),
                'date' => (string) $a->updated_at?->timezone('Asia/Jerusalem')->format('j.n'),
            ])->all());
    }

    /**
     * How many shoppers' questions each page answered: asked on it, or answered from it in the
     * search window (the answer names it among its sources).
     *
     * @param  Collection<int, CatalogContent>  $pages
     * @return array<string, int> by content id
     */
    private function answeredBy($pages): array
    {
        $ids = $pages->pluck('id')->map(fn ($id): string => (string) $id)->all();
        $byUrl = $pages->filter(fn (CatalogContent $p): bool => filled($p->url))->mapWithKeys(fn (CatalogContent $p): array => [self::urlKey((string) $p->url) => (string) $p->id])->all();
        $counts = [];

        AssistantAnswer::query()->whereIn('content_id', $ids)->where('status', AssistantAnswer::SHOWN)
            ->selectRaw('content_id, sum(asked_count) as n')->groupBy('content_id')->get()
            ->each(function ($row) use (&$counts): void {
                $counts[(string) $row->content_id] = (int) $row->n;
            });

        AssistantAnswer::query()->whereNull('content_id')->whereNull('product_id')->whereNotNull('sources')->where('status', AssistantAnswer::SHOWN)
            ->limit(2000)->get(['sources', 'asked_count'])
            ->each(function (AssistantAnswer $answer) use ($byUrl, &$counts): void {
                foreach (array_unique(array_filter(array_map(fn ($source): ?string => is_array($source) && filled($source['url'] ?? null) ? ($byUrl[self::urlKey((string) $source['url'])] ?? null) : null, (array) $answer->sources))) as $id) {
                    $counts[$id] = ($counts[$id] ?? 0) + max(1, (int) $answer->asked_count);
                }
            });

        return $counts;
    }

    /** An address without its scheme, "www.", query or closing slash, decoded: two spellings of one page meet. */
    private static function urlKey(string $url): string
    {
        $url = mb_strtolower(rawurldecode(trim($url)));
        $url = (string) preg_replace('#^[a-z]+://#', '', $url);
        $url = (string) preg_replace('#^www\.#', '', $url);
        $url = (string) preg_replace('#[?\#].*$#', '', $url);

        return rtrim($url, '/');
    }

    /** One line of a page's text, for the list. */
    private static function line(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        return mb_strlen($text) > 140 ? rtrim(mb_substr($text, 0, 138)).'…' : $text;
    }

    private function inShop(callable $callback): mixed
    {
        return app(TenantContext::class)->run((string) $this->shop, $callback);
    }

    /** "שאלה: ..." without its label. */
    private static function unlabel(string $line): string
    {
        return trim((string) preg_replace('/^[^:]{1,12}:\s*/u', '', $line));
    }
}
