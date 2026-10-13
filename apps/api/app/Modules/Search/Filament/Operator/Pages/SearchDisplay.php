<?php

namespace App\Modules\Search\Filament\Operator\Pages;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Settings\InvalidSettingValue;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Retrieval\Contracts\SemanticSearch;
use App\Modules\Search\Http\Controllers\SearchIndexController;
use App\Modules\Search\Support\ResultGroups;
use App\Modules\Tenancy\Models\Shop;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * How the search shows in the shop, on one screen: what works in the search box, where all the
 * results open, and how they look, with the shop's own products in a preview beside it.
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

    /** @var list<array{key: string, on: bool}> the groups in their order, each with its switch */
    public array $groups = [];

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
            $this->groups = [];

            return;
        }

        $color = SearchIndexController::color((string) Settings::get('search.button_color', $shop));

        $this->data = [
            'storefront' => Features::enabled('search.storefront', $shop),
            'photos' => Features::enabled('search.photos', $shop),
            'ask' => Features::enabled('assistant.on_search', $shop),
            'whatsapp' => Features::enabled('assistant.search_whatsapp', $shop),
            'whatsapp_message' => (string) Settings::get('assistant.search_whatsapp_message', $shop),
            'results' => (string) Settings::get('search.results', $shop),
            'mode_choice' => Features::enabled('search.mode_choice', $shop),
            'dropdown' => (string) Settings::get('search.dropdown_layout', $shop),
            'content_by_meaning' => Features::enabled('search.content_by_meaning', $shop),
            'drawer_side' => (string) Settings::get('search.drawer_side', $shop),
            'drawer_columns' => (string) Settings::get('search.drawer_columns', $shop),
            'suggestions' => (int) Settings::get('search.suggestions', $shop),
            'per_group' => (int) Settings::get('search.results_per_group', $shop),
            'price' => (bool) Settings::get('search.show_price', $shop),
            'cart' => (bool) Settings::get('search.show_cart', $shop),
            'similar' => Features::enabled('search.similar', $shop),
            'hover' => (bool) Settings::get('search.hover_image', $shop),
            'own_color' => $color !== '',
            'color' => $color !== '' ? $color : '#111827',
            'selector' => (string) Settings::get('search.input_selector', $shop),
        ];

        $shown = ResultGroups::parse((string) Settings::get('search.groups', $shop));
        $this->groups = [
            ...array_map(fn (string $key): array => ['key' => $key, 'on' => true], $shown),
            ...array_map(fn (string $key): array => ['key' => $key, 'on' => false], array_values(array_diff(ResultGroups::ALL, $shown))),
        ];
    }

    public function pickResults(string $mode): void
    {
        if (in_array($mode, ['panel', 'drawer', 'page'], true)) {
            $this->data['results'] = $mode;
        }
    }

    public function pickDevice(string $device): void
    {
        $this->device = $device === 'phone' ? 'phone' : 'desktop';
    }

    /** One group up or down the list. */
    public function moveGroup(int $at, int $by): void
    {
        $to = $at + $by;

        if (! isset($this->groups[$at], $this->groups[$to])) {
            return;
        }

        [$this->groups[$at], $this->groups[$to]] = [$this->groups[$to], $this->groups[$at]];
        $this->groups = array_values($this->groups);
    }

    public function save(): void
    {
        $shop = $this->shop;

        if ($shop === null) {
            return;
        }

        $groups = array_values(array_map(fn (array $g): string => (string) $g['key'], array_filter($this->groups, fn (array $g): bool => (bool) ($g['on'] ?? false))));

        if ($groups === []) {
            Notification::make()->warning()->title(__('search::ui.display.one_group'))->send();

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
                'search.mode_choice' => 'mode_choice',
                'search.content_by_meaning' => 'content_by_meaning',
            ] as $feature => $field) {
                Features::override($feature, (bool) ($d[$field] ?? false), $shop);
            }

            Settings::set('search.results', (string) ($d['results'] ?? 'panel'), $shop);
            Settings::set('search.dropdown_layout', (string) ($d['dropdown'] ?? 'list'), $shop);
            Settings::set('search.drawer_side', (string) ($d['drawer_side'] ?? 'start'), $shop);
            Settings::set('search.drawer_columns', (string) ($d['drawer_columns'] ?? '2'), $shop);
            Settings::set('search.suggestions', (int) ($d['suggestions'] ?? 6), $shop);
            Settings::set('search.results_per_group', (int) ($d['per_group'] ?? 12), $shop);
            Settings::set('search.show_price', (bool) ($d['price'] ?? true), $shop);
            Settings::set('search.show_cart', (bool) ($d['cart'] ?? true), $shop);
            Settings::set('search.hover_image', (bool) ($d['hover'] ?? false), $shop);
            Settings::set('search.groups', ResultGroups::compose($groups), $shop);
            Settings::set('search.button_color', ($d['own_color'] ?? false) ? SearchIndexController::color((string) ($d['color'] ?? '')) : '', $shop);

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
     * @return list<array{title: string, image: string|null, image2: string|null, price: string|null}>
     */
    public function previewProducts(): array
    {
        $shop = $this->shop;

        if ($shop === null) {
            return [];
        }

        return app(TenantContext::class)->run($shop, fn (): array => CatalogProduct::query()->active()
            ->where('in_stock', true)->whereNotNull('image_url')->orderBy('id')->limit(self::PREVIEW)
            ->get(['id', 'title', 'image_url', 'price', 'currency', 'payload'])
            ->map(fn (CatalogProduct $p): array => [
                'title' => (string) $p->title,
                'image' => $p->image_url,
                'image2' => data_get($p->payload, 'images.1.url'),
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
