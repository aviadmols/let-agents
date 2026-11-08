<?php

namespace App\Modules\Assistant\Filament\Merchant\Pages;

use App\Core\Tenancy\LocksShopToPanelTenant;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Contracts\KnowledgeProducts;
use App\Modules\Assistant\Filament\Operator\Pages\AboutStore as OperatorAboutStore;
use App\Modules\Tenancy\Models\Shop;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
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
    use LocksShopToPanelTenant;

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
