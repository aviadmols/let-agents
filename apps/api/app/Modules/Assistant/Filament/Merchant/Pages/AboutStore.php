<?php

namespace App\Modules\Assistant\Filament\Merchant\Pages;

use App\Core\Tenancy\LocksShopToPanelTenant;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Contracts\KnowledgeProducts;
use App\Modules\Admin\Models\User;
use App\Modules\Assistant\Actions\BuildShopKnowledge;
use App\Modules\Assistant\Filament\Merchant\Concerns\EditsSourceLines;
use App\Modules\Assistant\Filament\Operator\Pages\AboutStore as OperatorAboutStore;
use App\Modules\Assistant\Models\AssistantIgnoredSource;
use App\Modules\Retrieval\Models\RetrievalChunk;
use App\Modules\Tenancy\Models\Shop;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;
use UnitEnum;

/**
 * Store knowledge, as the designer's redesign lays it out (Tomer, 11.2026): everything Lets knows about the store and
 * where it learned it. One line to teach it something; then the pages and policies it read (with its posts and what the
 * product pages repeat), the products and what was understood of each, what the team added, and the rules of its
 * products; beside them, what Lets knows in numbers and when it last read the store. The products come from the scan
 * through KnowledgeProducts; the rest is this module's own (the operator's page, whose methods this one keeps). The
 * shop is fixed.
 */
final class AboutStore extends OperatorAboutStore
{
    use EditsSourceLines;
    use LocksShopToPanelTenant;

    /** Who may fix a line where it is written: who edits the shop (a viewer only looks), or an operator. */
    public function mayFixLines(): bool
    {
        $user = auth()->user();
        $shop = $this->shop === null ? null : Shop::query()->find($this->shop);

        return $user instanceof User && $shop !== null && ($user->is_operator || $user->canEditShop($shop));
    }

    public const TABS = ['pages', 'products', 'notes', 'rules'];

    private const STRIP = 12;

    private const CACHE_SECONDS = 120;

    /** The product open in the products tab, and the words that find one. */
    public ?string $product = null;

    public string $productSearch = '';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string|UnitEnum|null $navigationGroup = null;

    protected static ?int $navigationSort = 50;

    protected string $view = 'assistant::merchant.knowledge';

    public static function getNavigationLabel(): string
    {
        return __('assistant::store_knowledge.nav');
    }

    public function getTitle(): string
    {
        return __('assistant::store_knowledge.title');
    }

    public function getSubheading(): ?string
    {
        return null;
    }

    public function getHeader(): ?View
    {
        return view('admin::merchant.screen-head', ['title' => $this->getTitle(), 'lede' => __('assistant::store_knowledge.lede')]);
    }

    public function mount(): void
    {
        // The trait also declares mount(); this one wins, and the parent still loads the text.
        $this->lockShopToTenant();
        parent::mount();
        // the knowledge centre's old tabs (general, posts, found) are the pages tab now
        $this->tab = in_array($this->tab, self::TABS, true) ? $this->tab : 'pages';
        $this->limits = ['pages' => 6, 'posts' => 5, 'found' => 5, 'notes' => 20, 'rules' => 10] + $this->limits;
    }

    /** The operator's actions name its own tabs (posts after a post is added): they are the pages tab here. */
    public function rendering(): void
    {
        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'pages';
        }
    }

    public function show(string $tab): void
    {
        $this->tab = in_array($tab, self::TABS, true) ? $tab : 'pages';
    }

    public function more(string $tab): void
    {
        if (array_key_exists($tab, $this->limits)) {
            $this->limits[$tab] = (int) $this->limits[$tab] + 20;
        }
    }

    /** A line taught: kept, used from the next search, and checked against the site in the background. */
    public function addInfo(): void
    {
        parent::addInfo();
        $this->tab = 'notes';
    }

    /** A product picked from the row: what was understood of it opens beside. */
    public function pick(string $id): void
    {
        $this->product = mb_substr($id, 0, 64);
    }

    /** @return array{products: int, photos: int, pages: int, posts: int, read_at: string|null} */
    public function knows(): array
    {
        return (array) Cache::remember('knowledge:'.$this->tenant()->id.':numbers', self::CACHE_SECONDS, fn (): array => $this->products(fn (KnowledgeProducts $k): array => $k->numbers($this->tenant()))
            ?? ['products' => 0, 'photos' => 0, 'pages' => 0, 'posts' => 0, 'read_at' => null]);
    }

    /** @return list<array{id: string, title: string, picture: string|null, missing: bool}> */
    public function strip(): array
    {
        return $this->products(fn (KnowledgeProducts $k): array => $k->strip($this->tenant(), $this->productSearch, self::STRIP)) ?? [];
    }

    /** @return array<string, mixed>|null the open product, or the first in the row */
    public function openProduct(): ?array
    {
        $id = $this->product ?? ($this->strip()[0]['id'] ?? null);

        return $id === null ? null : $this->products(fn (KnowledgeProducts $k): ?array => $k->product($this->tenant(), $id));
    }

    /** A long text being written (the teach box opened for it), and one being changed in the list. */
    public string $textTitle = '';

    public string $textBody = '';

    public ?string $editingText = null;

    public string $editTextTitle = '';

    public string $editTextBody = '';

    /** A long text pasted: kept as an entry of its own, found by meaning a few minutes later (BuildShopKnowledge::addText). */
    public function addText(): void
    {
        abort_unless($this->mayFixLines(), 403);

        if (! $this->textFits($this->textBody)) {
            return;
        }

        $id = $this->inThisShop(fn (): ?string => app(BuildShopKnowledge::class)->addText($this->textTitle, $this->textBody));

        if ($id === null) {
            return;
        }

        $this->textTitle = '';
        $this->textBody = '';
        $this->tab = 'notes';
        $this->dispatch('lets-text-saved');
        Notification::make()->success()->title(__('assistant::store_knowledge.text.saved'))->body(__('assistant::store_knowledge.text.saved_body'))->send();
    }

    public function startText(string $id): void
    {
        $row = collect($this->texts())->firstWhere('id', $id);
        $this->editingText = $row === null ? null : $id;
        $this->editTextTitle = (string) ($row['title'] ?? '');
        $this->editTextBody = (string) ($row['body'] ?? '');
    }

    public function saveText(): void
    {
        abort_unless($this->mayFixLines(), 403);

        if ($this->editingText === null || ! $this->textFits($this->editTextBody)) {
            return;
        }

        $id = $this->editingText;
        $this->inThisShop(fn () => app(BuildShopKnowledge::class)->editText($id, $this->editTextTitle, $this->editTextBody));
        $this->cancelText();
        Notification::make()->success()->title(__('assistant::store_knowledge.text.saved'))->body(__('assistant::store_knowledge.text.saved_body'))->send();
    }

    public function cancelText(): void
    {
        $this->editingText = null;
        $this->editTextTitle = '';
        $this->editTextBody = '';
    }

    public function removeText(string $id): void
    {
        abort_unless($this->mayFixLines(), 403);
        $this->inThisShop(fn () => app(BuildShopKnowledge::class)->removeText($id));
        Notification::make()->success()->title(__('assistant::store_knowledge.text.removed'))->send();
    }

    /**
     * The pages and posts of the site the team stopped from the "why" of an answer, newest first.
     *
     * @return list<array{content_id: string, title: string, url: string|null, at: string|null}>
     */
    public function ignoredSources(): array
    {
        return $this->inThisShop(fn (): array => AssistantIgnoredSource::query()->latest()->get()
            ->map(fn (AssistantIgnoredSource $s): array => ['content_id' => $s->content_id, 'title' => $s->title, 'url' => $s->url, 'at' => $s->created_at?->toIso8601String()])->all());
    }

    /** A page the team stopped, brought back: the assistant answers from it again. */
    public function bringBack(string $contentId): void
    {
        abort_unless($this->mayFixLines(), 403);
        $this->inThisShop(fn () => app(BuildShopKnowledge::class)->unignore($contentId));
        Notification::make()->success()->title(__('assistant::store_knowledge.ignored.brought_back'))->send();
    }

    /**
     * The team's long texts, newest first, each with how many words it has and whether the assistant finds it yet: every
     * piece of it given its vector since it was last saved.
     *
     * @return list<array{id: string, title: string, body: string, at: string|null, words: int, ready: bool}>
     */
    public function texts(): array
    {
        return $this->inThisShop(function (): array {
            $texts = app(BuildShopKnowledge::class)->texts();
            $pieces = RetrievalChunk::query()->where('source', 'content')->whereIn('source_id', array_column($texts, 'content_id'))
                ->selectRaw('source_id, count(*) as pieces, count(embedded_at) as found, max(embedded_at) as since')->groupBy('source_id')->get()->keyBy('source_id');

            return array_map(function (array $text) use ($pieces): array {
                $piece = $pieces->get($text['content_id']);
                $since = $piece?->since === null ? null : Carbon::parse((string) $piece->since);

                return [
                    'id' => $text['id'],
                    'title' => $text['title'],
                    'body' => $text['body'],
                    'at' => $text['at'],
                    'words' => count(preg_split('/\s+/u', trim($text['body']), -1, PREG_SPLIT_NO_EMPTY) ?: []),
                    'ready' => $piece !== null && (int) $piece->pieces > 0 && (int) $piece->found === (int) $piece->pieces
                        && $since !== null && ($text['changed'] === null || $since->gte(Carbon::parse($text['changed'])->subSecond()) || Carbon::parse($text['changed'])->lt(now()->subMinutes(30))),
                ];
            }, $texts);
        });
    }

    private function textFits(string $body): bool
    {
        if (mb_strlen(trim($body)) <= BuildShopKnowledge::MAX_TEXT) {
            return true;
        }

        Notification::make()->warning()->title(__('assistant::store_knowledge.text.too_long', ['n' => number_format(BuildShopKnowledge::MAX_TEXT)]))->send();

        return false;
    }

    private function inThisShop(callable $callback): mixed
    {
        return app(TenantContext::class)->run((string) $this->tenant()->id, $callback);
    }

    /**
     * @template T
     *
     * @param  callable(KnowledgeProducts): T  $ask
     * @return T|null
     */
    private function products(callable $ask): mixed
    {
        foreach (app()->tagged(KnowledgeProducts::TAG) as $source) {
            try {
                return app(TenantContext::class)->run((string) $this->tenant()->id, fn () => $ask($source));
            } catch (Throwable $e) {
                report($e);
            }
        }

        return null;
    }

    private function tenant(): Shop
    {
        /** @var Shop $shop */
        $shop = Filament::getTenant();

        return $shop;
    }
}
