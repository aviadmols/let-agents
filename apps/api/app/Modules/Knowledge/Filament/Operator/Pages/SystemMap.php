<?php

namespace App\Modules\Knowledge\Filament\Operator\Pages;

use App\Core\Tenancy\NeedsShopContext;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Knowledge\Support\SystemMap as Map;
use App\Modules\Retrieval\Contracts\RunsRetrieval;
use App\Modules\Widget\Contracts\ExplainsPages;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * The shop's machinery, drawn: how its stores are built (products, pages, posts and orders into
 * an index by meaning), how its products are matched (code's candidates, the model's choice,
 * code's verdict, the relations that come out), and how one product's page is put together
 * from them, stage by stage.
 *
 * Every number is counted from the tables when the page opens. The map's lanes come from what
 * is there — a source another module adds to the index shows up as one more lane.
 */
final class SystemMap extends Page
{
    use NeedsShopContext;

    public const TABS = ['index', 'matching', 'page'];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static string|UnitEnum|null $navigationGroup = 'overview';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'system-map';

    protected string $view = 'knowledge::operator.system-map';

    #[Url]
    public string $tab = 'index';

    /** The catalogue ID of the product the matching trail and the page trace are about. */
    #[Url]
    public ?string $product = null;

    public string $search = '';

    public static function getNavigationLabel(): string
    {
        return __('knowledge::map.title');
    }

    public function getTitle(): string
    {
        return __('knowledge::map.title');
    }

    public function getSubheading(): ?string
    {
        return __('knowledge::map.help');
    }

    public function mount(): void
    {
        $this->tab = in_array($this->tab, self::TABS, true) ? $this->tab : 'index';
    }

    public function show(string $tab): void
    {
        $this->tab = in_array($tab, self::TABS, true) ? $tab : 'index';
    }

    public function choose(string $productId): void
    {
        $this->product = $productId;
        $this->search = '';
    }

    /** @return array<string, mixed> */
    public function indexMap(): array
    {
        return $this->inShop(fn (): array => Map::index());
    }

    /** @return array<string, mixed> */
    public function matchingMap(): array
    {
        return $this->inShop(fn (): array => Map::matching());
    }

    /** @return array<string, mixed>|null */
    public function trail(): ?array
    {
        return $this->product === null ? null : $this->inShop(fn (): ?array => Map::productMatching($this->product));
    }

    /**
     * The page bank for the chosen product, built now with its explanation: never the cached
     * one, so the trace is what the next shopper would get.
     *
     * @return array{bank: array<string, mixed>, stages: list<array<string, mixed>>}|null
     */
    public function pageTrace(): ?array
    {
        $shopId = app(TenantContext::class)->id();
        $product = $this->chosen();

        if ($shopId === null || $product === null) {
            return null;
        }

        $bank = app(ExplainsPages::class)->explain($shopId, 'product', $product->external_id, app()->getLocale() === 'en' ? 'en' : 'he');

        return ['bank' => $bank, 'stages' => Map::pageStages($bank['trace'] ?? [])];
    }

    public function chosen(): ?CatalogProduct
    {
        return $this->product === null ? null : $this->inShop(fn (): ?CatalogProduct => CatalogProduct::query()->find($this->product));
    }

    /** @return list<array{id: string, title: string, external_id: string}> */
    public function results(): array
    {
        $term = trim($this->search);

        if (mb_strlen($term) < 2) {
            return [];
        }

        return $this->inShop(fn (): array => CatalogProduct::query()->active()
            ->where(fn ($q) => $q->where('title', 'like', '%'.$term.'%')->orWhere('external_id', $term)->orWhere('sku', $term))
            ->orderBy('title')->limit(8)->get(['id', 'title', 'external_id'])
            ->map(fn (CatalogProduct $p): array => ['id' => $p->id, 'title' => $p->title, 'external_id' => $p->external_id])
            ->all());
    }

    /** Ask the matching model again about the chosen product, now, whatever changed. */
    public function askAgain(): void
    {
        $shopId = app(TenantContext::class)->id();

        if ($shopId === null || $this->product === null) {
            return;
        }

        $run = app(RunsRetrieval::class)->match($shopId, [$this->product]);

        Notification::make()
            ->title((string) $run->summary())
            ->{$run->status->value === 'succeeded' ? 'success' : 'danger'}()
            ->send();
    }

    /** "←" in Hebrew, "→" in English: the way the work flows on the screen. */
    public function arrow(): string
    {
        return app()->getLocale() === 'he' ? '←' : '→';
    }

    private function inShop(callable $read): mixed
    {
        $shopId = app(TenantContext::class)->id();

        return $shopId === null ? null : app(TenantContext::class)->run($shopId, $read);
    }
}
