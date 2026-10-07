<?php

namespace App\Modules\Widget\Filament\Operator\Pages;

use App\Core\Facades\Settings;
use App\Core\Modules\ModuleRepository;
use App\Core\Tenancy\NeedsShopContext;
use App\Core\Tenancy\TenantContext;
use App\Modules\Analytics\Models\AnalyticsEvent;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Enrichment\Contracts\RereadsPages;
use App\Modules\Widget\Actions\BuildPageBank;
use App\Modules\Widget\Models\WidgetCuration;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;

/**
 * One page of the store as the widget builds it, with why each circle and each product is there,
 * and the store team's say: pin a product first, hide one, hide a circle, or add a product code
 * did not pick. What the team decides overrides code and learning (BuildPageBank::curated()).
 */
class ProductPage extends Page
{
    use NeedsShopContext;

    private const SEARCH_RESULTS = 12;

    private const QUESTIONS = 20;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?int $navigationSort = 14;

    protected static ?string $slug = 'widget/page';

    protected string $view = 'widget::operator.page';

    #[Url]
    public ?string $shop = null;

    #[Url]
    public string $type = 'product';

    #[Url]
    public ?string $id = null;

    public string $search = '';

    public string $addSearch = '';

    public ?string $addTo = null;

    public static function getNavigationLabel(): string
    {
        return __('widget::ui.page.title');
    }

    public function getTitle(): string
    {
        return __('widget::ui.page.title');
    }

    public function getSubheading(): ?string
    {
        return __('widget::ui.page.subheading');
    }

    /** False in the merchant panel, where every search is inside the one shop in the address. */
    public function picksShop(): bool
    {
        return true;
    }

    public function pick(string $shopId, string $type, string $externalId): void
    {
        $this->shop = $shopId;
        $this->type = $type;
        $this->id = $externalId;
        $this->search = '';
        $this->addTo = null;
    }

    /** @return Collection<int, array{shop_id: string, type: string, external_id: string, title: string, shop: string}> */
    public function matches(): Collection
    {
        $term = trim($this->search);

        if (mb_strlen($term) < 2) {
            return collect();
        }

        return app(TenantContext::class)->runUnscoped(function () use ($term): Collection {
            $products = CatalogProduct::query()->with('shop:id,name')->whereNull('removed_at')
                ->where(fn ($q) => $q->where('title', 'like', "%{$term}%")->orWhere('external_id', $term))
                ->orderBy('title')->limit(self::SEARCH_RESULTS)->get(['id', 'shop_id', 'external_id', 'title'])
                ->map(fn (CatalogProduct $p): array => ['shop_id' => $p->shop_id, 'type' => 'product', 'external_id' => $p->external_id, 'title' => $p->title, 'shop' => (string) $p->shop?->name]);
            $articles = CatalogContent::query()->with('shop:id,name')->whereNull('removed_at')
                ->where('title', 'like', "%{$term}%")
                ->orderBy('title')->limit(4)->get(['id', 'shop_id', 'external_id', 'title'])
                ->map(fn (CatalogContent $c): array => ['shop_id' => $c->shop_id, 'type' => 'content', 'external_id' => $c->external_id, 'title' => $c->title, 'shop' => (string) $c->shop?->name]);

            return $products->concat($articles)->values();
        });
    }

    /**
     * Something to start from when nobody has typed anything.
     *
     * A search box on its own asks a person to already know which page they want. Most of the
     * time they want the article that was published this week, or any article at all, so the
     * most recently written ones are offered and the box is for when they want a particular
     * one. Articles first: a product's page can be reached from the catalogue, an article's
     * cannot.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function recent(): Collection
    {
        if (trim($this->search) !== '' || $this->id !== null) {
            return collect();
        }

        $shop = $this->shop ?? app(TenantContext::class)->id();

        return app(TenantContext::class)->runUnscoped(function () use ($shop): Collection {
            $articles = CatalogContent::query()->whereNull('removed_at')
                ->when($shop !== null, fn ($q) => $q->where('shop_id', $shop))
                ->orderByDesc('source_updated_at')->limit(6)->get(['shop_id', 'external_id', 'title', 'type'])
                ->map(fn (CatalogContent $c): array => ['shop_id' => $c->shop_id, 'type' => 'content', 'external_id' => $c->external_id, 'title' => $c->title]);

            $products = CatalogProduct::query()->whereNull('removed_at')
                ->when($shop !== null, fn ($q) => $q->where('shop_id', $shop))
                ->orderByDesc('source_updated_at')->limit(4)->get(['shop_id', 'external_id', 'title'])
                ->map(fn (CatalogProduct $p): array => ['shop_id' => $p->shop_id, 'type' => 'product', 'external_id' => $p->external_id, 'title' => $p->title]);

            return $articles->concat($products)->values();
        });
    }

    /** Products to add into the section being edited. @return Collection<int, CatalogProduct> */
    public function addMatches(): Collection
    {
        $term = trim($this->addSearch);

        if ($this->shop === null || $this->addTo === null || mb_strlen($term) < 2) {
            return collect();
        }

        return app(TenantContext::class)->run($this->shop, fn () => CatalogProduct::query()->active()->where('in_stock', true)
            ->where(fn ($q) => $q->where('title', 'like', "%{$term}%")->orWhere('external_id', $term))
            ->where('external_id', '!=', (string) $this->id)
            ->orderBy('title')->limit(self::SEARCH_RESULTS)->get(['id', 'external_id', 'title']));
    }

    /** @return array<string, mixed>|null */
    public function page(): ?array
    {
        if ($this->shop === null || $this->id === null) {
            return null;
        }

        $bank = app(BuildPageBank::class)->handle($this->shop, $this->type, $this->id, 'he', explain: true);
        $curations = app(TenantContext::class)->run($this->shop, fn () => WidgetCuration::query()->where('page_type', $this->type)->where('page_external_id', $this->id)->get());
        $subject = app(TenantContext::class)->run($this->shop, fn () => $this->type === 'product'
            ? CatalogProduct::query()->where('external_id', $this->id)->first()
            : CatalogContent::query()->where('external_id', $this->id)->first());
        $connection = app(TenantContext::class)->run($this->shop, fn () => StoreConnection::query()->latest()->first());

        return [
            'bank' => $bank,
            'subject' => $subject,
            'curations' => $curations,
            'hidden_sections' => $curations->where('action', WidgetCuration::HIDE)->where('item_external_id', '')->pluck('candidate')->all(),
            'pinned_sections' => $curations->where('action', WidgetCuration::PIN)->where('item_external_id', '')->pluck('candidate')->all(),
            'hidden_items' => $curations->where('action', WidgetCuration::HIDE)->where('item_external_id', '!=', '')->groupBy('candidate')->map(fn (Collection $c) => $c->pluck('item_external_id')->all())->all(),
            'pinned_items' => $curations->where('action', WidgetCuration::PIN)->where('item_external_id', '!=', '')->groupBy('candidate')->map(fn (Collection $c) => $c->pluck('item_external_id')->all())->all(),
            'preview_url' => $subject?->url && $connection ? $subject->url.(str_contains($subject->url, '?') ? '&' : '?').'let_agents_preview='.$connection->previewKey() : $subject?->url,
            'titles' => $this->titles($curations->pluck('item_external_id')->filter()->all()),
            'activity' => $this->activity(),
            'extras' => $this->extras($bank),
            'questions' => $this->questions($subject),
        ];
    }

    /**
     * What visitors did with each part of the widget on this page, counted from the events
     * themselves so today counts too, not from the nightly scores. Preview visits are left out.
     *
     * @return array{days: int, views: int, by_candidate: array<string, array<string, int>>, by_item: array<string, array<string, array<string, int>>>}
     */
    private function activity(): array
    {
        $days = (int) Settings::get('analytics.score_window_days', $this->shop);
        $column = $this->type === 'content' ? 'content_external_id' : 'product_external_id';

        $rows = app(TenantContext::class)->run($this->shop, fn () => AnalyticsEvent::query()
            ->where('page_type', $this->type)
            ->where($column, $this->id)
            ->where('preview', false)
            ->where('occurred_at', '>=', now()->subDays($days))
            ->select('candidate_id', 'type', 'item_external_id', 'source', 'result', DB::raw('count(*) as n'))
            ->groupBy('candidate_id', 'type', 'item_external_id', 'source', 'result')
            ->get());

        $byCandidate = [];
        $byItem = [];
        $views = 0;

        foreach ($rows as $row) {
            $n = (int) $row->n;

            if ($row->type === 'page_view') {
                $views += $n;

                continue;
            }

            $counter = match ($row->type) {
                'exposure' => 'exposures',
                'open' => 'opens',
                'click' => 'clicks',
                'chat_question' => 'questions',
                // An add from the store's own button belongs to the product, not to a circle.
                'add_to_cart' => $row->source === 'widget' && $row->result === 'added' ? 'adds' : null,
                default => null,
            };

            if ($counter === null || $row->candidate_id === null) {
                continue;
            }

            $candidate = (string) $row->candidate_id;
            $byCandidate[$candidate][$counter] = ($byCandidate[$candidate][$counter] ?? 0) + $n;

            if ($row->item_external_id !== null && in_array($counter, ['clicks', 'adds'], true)) {
                $item = (string) $row->item_external_id;
                $byItem[$candidate][$item][$counter] = ($byItem[$candidate][$item][$counter] ?? 0) + $n;
            }
        }

        return ['days' => $days, 'views' => $views, 'by_candidate' => $byCandidate, 'by_item' => $byItem];
    }

    /**
     * Everything the widget puts on the page that is not one of the circles: the quote above them,
     * the popularity line, the WhatsApp strip, the question box, and the two that depend on the
     * visitor rather than the page.
     *
     * @param  array<string, mixed>  $bank
     * @return list<array{key: string, on: bool, detail: string|null}>
     */
    private function extras(array $bank): array
    {
        // The widget takes the first superlative, or the first highlight the shop does not repeat
        // on many products, and shows it as a quote without a click.
        $quote = null;
        foreach ($bank['sections'] as $section) {
            if (! empty($section['lines'])) {
                $quote = $section['lines'][0]['text'];
                break;
            }
            $items = array_values(array_filter($section['items'] ?? [], fn (array $item): bool => empty($item['common'])));
            if ($items !== []) {
                $quote = $items[0]['key'].' '.$items[0]['text'];
                break;
            }
        }

        return [
            ['key' => 'quote', 'on' => $quote !== null, 'detail' => $quote],
            ['key' => 'popularity', 'on' => ($bank['popularity'] ?? null) !== null, 'detail' => $bank['popularity']['text'] ?? ($bank['popularity']['badge'] ?? null)],
            ['key' => 'contact', 'on' => ($bank['contact'] ?? null) !== null, 'detail' => $bank['contact']['title'] ?? null],
            ['key' => 'ask', 'on' => (bool) ($bank['ask'] ?? false), 'detail' => null],
            ['key' => 'recent', 'on' => (bool) ($bank['recent'] ?? false), 'detail' => null],
            ['key' => 'signup', 'on' => ($bank['signup'] ?? null) !== null, 'detail' => $bank['signup']['title'] ?? null],
            ['key' => 'compare', 'on' => ($bank['compare'] ?? null) !== null, 'detail' => null],
        ];
    }

    /**
     * What shoppers asked about this product, most asked first. Unanswered ones are the store
     * team's to answer, in "Shopper questions".
     *
     * @return Collection<int, AssistantAnswer>
     */
    private function questions(mixed $subject): Collection
    {
        if (! $subject instanceof CatalogProduct || app(ModuleRepository::class)->get('Assistant')?->enabled !== true) {
            return collect();
        }

        return app(TenantContext::class)->run($this->shop, fn () => AssistantAnswer::query()
            ->where('product_id', $subject->id)
            ->orderByDesc('asked_count')
            ->orderByDesc('last_asked_at')
            ->limit(self::QUESTIONS)
            ->get());
    }

    public function pin(string $candidate, string $item = ''): void
    {
        $this->decide($candidate, $item, WidgetCuration::PIN);
    }

    public function hide(string $candidate, string $item = ''): void
    {
        $this->decide($candidate, $item, WidgetCuration::HIDE);
    }

    /** Back to what code and learning decide. */
    public function clear(string $candidate, string $item = ''): void
    {
        $this->decide($candidate, $item, null);
    }

    public function startAdding(string $candidate): void
    {
        $this->addTo = $candidate;
        $this->addSearch = '';
    }

    public function add(string $externalId): void
    {
        if ($this->addTo !== null) {
            $this->decide($this->addTo, $externalId, WidgetCuration::PIN);
        }

        $this->addTo = null;
        $this->addSearch = '';
    }

    private function decide(string $candidate, string $item, ?string $action): void
    {
        if ($this->shop === null || $this->id === null) {
            return;
        }

        app(TenantContext::class)->run($this->shop, function () use ($candidate, $item, $action): void {
            $query = WidgetCuration::query()->where('page_type', $this->type)->where('page_external_id', $this->id)->where('candidate', $candidate)->where('item_external_id', $item);

            if ($action === null) {
                $query->delete();
            } else {
                WidgetCuration::query()->updateOrCreate(
                    ['shop_id' => $this->shop, 'page_type' => $this->type, 'page_external_id' => $this->id, 'candidate' => $candidate, 'item_external_id' => $item],
                    ['action' => $action, 'user_id' => Auth::id()],
                );
            }
        });

        // The storefront reads a cached page; the team's decision shows on the next load.
        foreach (BuildPageBank::LOCALES as $locale) {
            Cache::forget("widget:page:{$this->shop}:{$this->type}:{$this->id}:{$locale}");
        }

        Notification::make()->success()->title(__('widget::ui.page.saved'))->send();
    }

    /** @param list<string> $externalIds @return array<string, string> */
    private function titles(array $externalIds): array
    {
        if ($externalIds === [] || $this->shop === null) {
            return [];
        }

        return app(TenantContext::class)->run($this->shop, fn () => CatalogProduct::query()->whereIn('external_id', $externalIds)->pluck('title', 'external_id')->all());
    }

    /**
     * What a fresh reading changed in the widget: the lines gained and lost, and the sections
     * whose count moved. Named apart from rescan() on purpose — Livewire's $wire proxy resolves a
     * property before a method, so a property of the same name makes the button do nothing.
     *
     * @var array<string, mixed>|null
     */
    public ?array $changed = null;

    public function rescan(): void
    {
        if ($this->shop === null || $this->id === null) {
            return;
        }

        $before = $this->shape(app(BuildPageBank::class)->handle($this->shop, $this->type, $this->id, 'he'));
        app(RereadsPages::class)->reread($this->shop, $this->type, $this->id);

        // The storefront reads the bank from a cache; a rescan is the one time it must not.
        foreach (['he', 'en'] as $locale) {
            Cache::forget("widget:page:{$this->shop}:{$this->type}:{$this->id}:{$locale}");
        }

        $after = $this->shape(app(BuildPageBank::class)->handle($this->shop, $this->type, $this->id, 'he'));

        $this->changed = [
            'added' => array_values(array_diff($after['lines'], $before['lines'])),
            'removed' => array_values(array_diff($before['lines'], $after['lines'])),
            'sections' => $this->moved($before['sections'], $after['sections']),
        ];
    }

    /**
     * The sections whose size changed, as candidate => [before, after].
     *
     * @param  array<string, int>  $before
     * @param  array<string, int>  $after
     * @return array<string, array{int, int}>
     */
    private function moved(array $before, array $after): array
    {
        $moved = [];

        foreach ($after + $before as $candidate => $ignored) {
            $was = $before[$candidate] ?? 0;
            $is = $after[$candidate] ?? 0;

            if ($was !== $is) {
                $moved[$candidate] = [$was, $is];
            }
        }

        return $moved;
    }

    /**
     * A bank reduced to the lines a person would notice changing: each section with its count,
     * every point and promise as text.
     *
     * @param  array<string, mixed>  $bank
     * @return array{sections: array<string, int>, lines: list<string>}
     */
    private function shape(array $bank): array
    {
        $sections = [];
        $lines = [];

        foreach ((array) ($bank['sections'] ?? []) as $section) {
            $count = count((array) ($section['products'] ?? $section['items'] ?? $section['guides'] ?? $section['specs'] ?? $section['lines'] ?? []));
            $sections[(string) $section['candidate']] = $count;

            foreach ((array) ($section['items'] ?? []) as $item) {
                $lines[] = trim(($item['key'] ?? '').' '.($item['text'] ?? ''));
            }
            foreach ((array) ($section['lines'] ?? []) as $line) {
                $lines[] = (string) ($line['text'] ?? '');
            }
        }

        foreach ((array) ($bank['assurances'] ?? []) as $assurance) {
            $lines[] = (string) ($assurance['text'] ?? '');
        }

        return ['sections' => $sections, 'lines' => array_values(array_filter(array_unique($lines)))];
    }

    public function maxProducts(): int
    {
        return $this->shop === null ? 0 : (int) Settings::get('widget.max_products', $this->shop);
    }
}
