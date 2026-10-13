<?php

namespace App\Modules\Shortlist\Filament\Operator\Pages;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Settings\InvalidSettingValue;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Shortlist\Actions\FindThemeCards;
use App\Modules\Shortlist\Models\ShortlistCount;
use App\Modules\Shortlist\Models\ShortlistWishlist;
use App\Modules\Tenancy\Models\Shop;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Compare and wishlist in one store: whether they are on and where their buttons go, and what
 * shoppers do with them: the lists kept, how many are in a store account, the products saved
 * most, the comparisons opened and the products compared most.
 *
 * Plain fields, saved through Features and Settings, so a value out of its range is refused.
 */
class Shortlists extends Page
{
    /** The days the numbers cover. */
    public const DAYS = 30;

    private const TOP = 10;

    /** Lists read for the products saved most; a store with more reads its latest. */
    private const LISTS_READ = 5000;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;

    protected static string|UnitEnum|null $navigationGroup = 'shoppers';

    protected static ?int $navigationSort = 60;

    protected static ?string $slug = 'shortlist';

    protected string $view = 'shortlist::operator.shortlists';

    #[Url]
    public ?string $shop = null;

    /** @var array<string, mixed> */
    public array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('shortlist::ui.title');
    }

    public function getTitle(): string
    {
        return __('shortlist::ui.title');
    }

    public function getSubheading(): ?string
    {
        return __('shortlist::ui.subheading');
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

    /** The shop's values, as the store reads them now. */
    public function load(): void
    {
        $shop = $this->shop;

        $this->data = $shop === null ? [] : [
            'compare' => Features::enabled('shortlist.compare', $shop),
            'wishlist' => Features::enabled('shortlist.wishlist', $shop),
            'compare_max' => (int) Settings::get('shortlist.compare_max', $shop),
            'wishlist_max' => (int) Settings::get('shortlist.wishlist_max', $shop),
            'card_selector' => (string) Settings::get('shortlist.card_selector', $shop),
            'product_selector' => (string) Settings::get('shortlist.product_selector', $shop),
            'wishlist_floating' => (bool) Settings::get('shortlist.wishlist_floating', $shop),
            'wishlist_header' => (bool) Settings::get('shortlist.wishlist_header', $shop),
            'wishlist_whatsapp' => (bool) Settings::get('shortlist.wishlist_whatsapp', $shop),
            'wishlist_place' => (string) Settings::get('shortlist.wishlist_place', $shop),
        ];
    }

    public function save(): void
    {
        $shop = $this->shop;

        if ($shop === null) {
            return;
        }

        $d = $this->data;

        try {
            Features::override('shortlist.compare', (bool) ($d['compare'] ?? false), $shop);
            Features::override('shortlist.wishlist', (bool) ($d['wishlist'] ?? false), $shop);
            Settings::set('shortlist.compare_max', (int) ($d['compare_max'] ?? 5), $shop);
            Settings::set('shortlist.wishlist_max', (int) ($d['wishlist_max'] ?? 100), $shop);
            Settings::set('shortlist.wishlist_floating', (bool) ($d['wishlist_floating'] ?? true), $shop);
            Settings::set('shortlist.wishlist_header', (bool) ($d['wishlist_header'] ?? false), $shop);
            Settings::set('shortlist.wishlist_whatsapp', (bool) ($d['wishlist_whatsapp'] ?? true), $shop);

            // Empty: no buttons there (no category cards; not on the product page; no heart in the header).
            foreach (['card_selector', 'product_selector', 'wishlist_place'] as $key) {
                Settings::set("shortlist.{$key}", trim((string) ($d[$key] ?? '')), $shop);
            }
        } catch (InvalidSettingValue $e) {
            Notification::make()->danger()->title(__('shortlist::ui.invalid'))->body($e->getMessage())->send();

            return;
        }

        Notification::make()->success()->title(__('shortlist::ui.saved'))->body(__('shortlist::ui.saved_body'))->send();
        $this->load();
    }

    /** A page of the store to look for the cards on first, when the manager names one. */
    public string $cardsPage = '';

    /** The theme's product cards, found on the store's own category page and put in the field, not saved. */
    public function findCards(): void
    {
        if ($this->shop === null) {
            return;
        }

        $found = app(FindThemeCards::class)->handle($this->shop, $this->cardsPage !== '' ? $this->cardsPage : null);

        if (! $found['found']) {
            $no = ($found['reason'] ?? '') === 'no_page';
            // What each page answered, so the manager sees why: blocked, empty, or no cards found.
            $tried = implode("\n", array_map(fn (array $t): string => __('shortlist::ui.cards_tried', ['page' => rawurldecode($t['page']), 'status' => (string) ($t['status'] ?? '—'), 'links' => (string) $t['links']]), $found['tried']));
            Notification::make()->warning()->title(__($no ? 'shortlist::ui.cards_no_page' : 'shortlist::ui.cards_not_found'))->body($no ? null : __('shortlist::ui.cards_not_found_body')."\n".$tried)->persistent()->send();

            return;
        }

        $this->data['card_selector'] = (string) $found['selector'];
        Notification::make()->success()->title(__('shortlist::ui.cards_found', ['cards' => (string) $found['cards']]))->body(__('shortlist::ui.cards_found_body', ['page' => rawurldecode((string) $found['page'])]))->send();
    }

    /**
     * What shoppers did, over the last DAYS days.
     *
     * @return array{lists: int, accounts: int, saved: int, compares: int, wished: list<array<string, mixed>>, compared: list<array<string, mixed>>}|null
     */
    public function numbers(): ?array
    {
        $shop = $this->shop;

        if ($shop === null) {
            return null;
        }

        return app(TenantContext::class)->run($shop, function (): array {
            $since = now()->subDays(self::DAYS);
            $lists = ShortlistWishlist::query()->where('updated_at', '>=', $since)->latest('updated_at')->limit(self::LISTS_READ)->get(['customer_ref', 'items']);

            $wished = [];
            foreach ($lists as $list) {
                foreach ((array) $list->items as $id) {
                    $wished[(string) $id] = ($wished[(string) $id] ?? 0) + 1;
                }
            }
            arsort($wished);

            $compared = ShortlistCount::query()->where('kind', ShortlistCount::COMPARE)->where('item', '!=', '')->whereDate('day', '>=', $since->toDateString())
                ->selectRaw('item, sum(n) as total')->groupBy('item')->orderByDesc('total')->orderBy('item')->limit(self::TOP)->pluck('total', 'item')->all();

            return [
                'lists' => $lists->count(),
                'accounts' => $lists->whereNotNull('customer_ref')->count(),
                'saved' => array_sum($wished),
                'compares' => (int) ShortlistCount::query()->where('kind', ShortlistCount::COMPARE)->where('item', '')->whereDate('day', '>=', $since->toDateString())->sum('n'),
                'wished' => $this->products(array_slice($wished, 0, self::TOP, true)),
                'compared' => $this->products($compared),
            ];
        });
    }

    /**
     * The products behind a count, by the store's id, in the count's order.
     *
     * @param  array<string, int|string>  $counts
     * @return list<array{title: string, url: string|null, image: string|null, n: int}>
     */
    private function products(array $counts): array
    {
        $products = CatalogProduct::query()->whereIn('external_id', array_map('strval', array_keys($counts)))->get(['external_id', 'title', 'url', 'image_url'])->keyBy('external_id');
        $out = [];

        foreach ($counts as $id => $n) {
            $product = $products->get((string) $id);
            $out[] = ['title' => (string) ($product?->title ?? $id), 'url' => $product?->url, 'image' => $product?->image_url, 'n' => (int) $n];
        }

        return $out;
    }
}
