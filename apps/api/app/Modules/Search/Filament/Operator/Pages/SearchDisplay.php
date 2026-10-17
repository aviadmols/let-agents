<?php

namespace App\Modules\Search\Filament\Operator\Pages;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Settings\InvalidSettingValue;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Retrieval\Contracts\SemanticSearch;
use App\Modules\Tenancy\Models\Shop;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * How the search shows in the shop, on one screen: what works in the search drawer and what each
 * product in it carries, with the shop's own products in a preview beside it.
 *
 * Every switch here is a feature or a setting the store already reads; the screen only writes
 * them, through Features and Settings, so a value outside its declared range is refused.
 */
class SearchDisplay extends Page
{
    /** The products in the preview. */
    private const PREVIEW = 6;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'discovery';

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'search/display';

    protected string $view = 'search::operator.search-display';

    #[Url]
    public ?string $shop = null;

    /** @var array<string, mixed> */
    public array $data = [];

    /** The preview's width: desktop or phone. Not saved. */
    public string $device = 'desktop';

    public static function getNavigationLabel(): string
    {
        return __('search::ui.display.title');
    }

    public function getTitle(): string
    {
        return __('search::ui.display.title');
    }

    public function getSubheading(): ?string
    {
        return __('search::ui.display.subheading');
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

        if ($shop === null) {
            $this->data = [];

            return;
        }

        $this->data = [
            'storefront' => Features::enabled('search.storefront', $shop),
            'photos' => Features::enabled('search.photos', $shop),
            'ask' => Features::enabled('assistant.on_search', $shop),
            'whatsapp' => Features::enabled('assistant.search_whatsapp', $shop),
            'whatsapp_message' => (string) Settings::get('assistant.search_whatsapp_message', $shop),
            'content_by_meaning' => Features::enabled('search.content_by_meaning', $shop),
            'per_group' => (int) Settings::get('search.results_per_group', $shop),
            'price' => (bool) Settings::get('search.show_price', $shop),
            'cart' => (bool) Settings::get('search.show_cart', $shop),
            'images' => (string) Settings::get('search.image_fit', $shop),
            'similar' => Features::enabled('search.similar', $shop),
            'selector' => (string) Settings::get('search.input_selector', $shop),
        ];
    }

    public function pickDevice(string $device): void
    {
        $this->device = $device === 'phone' ? 'phone' : 'desktop';
    }

    public function save(): void
    {
        $shop = $this->shop;

        if ($shop === null) {
            return;
        }

        $d = $this->data;

        try {
            foreach ([
                'search.storefront' => 'storefront',
                'search.photos' => 'photos',
                'assistant.on_search' => 'ask',
                'assistant.search_whatsapp' => 'whatsapp',
                'search.similar' => 'similar',
                'search.content_by_meaning' => 'content_by_meaning',
            ] as $feature => $field) {
                Features::override($feature, (bool) ($d[$field] ?? false), $shop);
            }

            Settings::set('search.results_per_group', (int) ($d['per_group'] ?? 12), $shop);
            Settings::set('search.show_price', (bool) ($d['price'] ?? true), $shop);
            Settings::set('search.show_cart', (bool) ($d['cart'] ?? true), $shop);
            Settings::set('search.image_fit', ($d['images'] ?? 'cover') === 'frame' ? 'frame' : 'cover', $shop);

            $message = trim((string) ($d['whatsapp_message'] ?? ''));
            $message === '' ? Settings::clear('assistant.search_whatsapp_message', $shop) : Settings::set('assistant.search_whatsapp_message', $message, $shop);

            $selector = trim((string) ($d['selector'] ?? ''));
            $selector === '' ? Settings::clear('search.input_selector', $shop) : Settings::set('search.input_selector', $selector, $shop);
        } catch (InvalidSettingValue $e) {
            Notification::make()->danger()->title(__('search::ui.display.invalid'))->body($e->getMessage())->send();

            return;
        }

        Notification::make()->success()->title(__('search::ui.display.saved'))->body(__('search::ui.display.saved_body'))->send();
        $this->load();
    }

    /** The shop's platform: the field's name and where the switch is differ between them. */
    public function shopPlatform(): string
    {
        return $this->shop === null ? '' : (string) Shop::query()->whereKey($this->shop)->first()?->platform->value;
    }

    /**
     * What the shop has ready, for the lines under each switch.
     *
     * @return array{pictures: bool, number: ?string}
     */
    public function status(): array
    {
        $shop = $this->shop;

        if ($shop === null) {
            return ['pictures' => false, 'number' => null];
        }

        $number = (string) preg_replace('/\D/', '', (string) Settings::overrideFor('widget.whatsapp_number', $shop));

        return [
            'pictures' => app(TenantContext::class)->run($shop, fn (): bool => app(SemanticSearch::class)->picturesReady()),
            'number' => Features::enabled('widget.whatsapp', $shop) && strlen($number) >= 8 ? $number : null,
        ];
    }

    /**
     * A few of the shop's own products for the preview, the ones with pictures first.
     *
     * @return list<array{title: string, image: string|null, price: string|null}>
     */
    public function previewProducts(): array
    {
        $shop = $this->shop;

        if ($shop === null) {
            return [];
        }

        return app(TenantContext::class)->run($shop, fn (): array => CatalogProduct::query()->active()
            ->where('in_stock', true)->whereNotNull('image_url')->orderBy('id')->limit(self::PREVIEW)
            ->get(['id', 'title', 'image_url', 'price', 'currency'])
            ->map(fn (CatalogProduct $p): array => [
                'title' => (string) $p->title,
                'image' => $p->image_url,
                'price' => $p->price === null ? null : self::money((float) $p->price, $p->currency),
            ])->all());
    }

    private static function money(float $amount, ?string $currency): string
    {
        $number = number_format($amount, floor($amount) === $amount ? 0 : 2);

        return match (strtoupper((string) $currency)) {
            'ILS', '' => '₪'.$number,
            'USD' => '$'.$number,
            'EUR' => '€'.$number,
            default => $number.' '.strtoupper((string) $currency),
        };
    }
}
