<?php

namespace App\Modules\Shortlist\Filament\Operator\Pages;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Settings\InvalidSettingValue;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Shortlist\Models\ShortlistCount;
use App\Modules\Shortlist\Models\ShortlistWishlist;
use App\Modules\Sms\Contracts\Phone;
use App\Modules\Sms\Contracts\SendsSms;
use App\Modules\Tenancy\Models\Shop;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Compare and wishlist in one store (both live in the search drawer): whether they are on, sharing
 * and keeping the list by phone, and what shoppers do with them: the lists kept, how many are in a store account, the products saved
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

    /** Lists shown on the screen; the export has all. */
    private const LISTS_SHOWN = 100;

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

    /**
     * Which part of the screen shows: both in the operator panel; in the shop's panel the wishlist
     * and the compare box are two screens of their own.
     */
    public function part(): ?string
    {
        return null;
    }

    public function shows(string $part): bool
    {
        return $this->part() === null || $this->part() === $part;
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
            'wishlist_whatsapp' => (bool) Settings::get('shortlist.wishlist_whatsapp', $shop),
            'wishlist_phone' => Features::enabled('shortlist.wishlist_phone', $shop),
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
            Settings::set('shortlist.wishlist_whatsapp', (bool) ($d['wishlist_whatsapp'] ?? true), $shop);
            Features::override('shortlist.wishlist_phone', (bool) ($d['wishlist_phone'] ?? false), $shop);
        } catch (InvalidSettingValue $e) {
            Notification::make()->danger()->title(__('shortlist::ui.invalid'))->body($e->getMessage())->send();

            return;
        }

        Notification::make()->success()->title(__('shortlist::ui.saved'))->body(__('shortlist::ui.saved_body'))->send();
        $this->load();
    }

    /** Whether the platform can send SMS: without it, keeping the list by phone shows nowhere. */
    public function smsReady(): bool
    {
        return app(SendsSms::class)->ready();
    }

    /**
     * Every wishlist of the shop, the latest changed first: who keeps it (a store account, a phone,
     * a browser), the number in full only when the shopper agreed to hear from the store, and the
     * products in it.
     *
     * @return list<array{who: string, phone: string|null, consent: bool, count: int, products: list<string>, updated: string, ip: string|null, device: string|null, browser: string|null, os: string|null, referrer: string|null, session: string|null}>
     */
    public function lists(?int $limit = self::LISTS_SHOWN): array
    {
        $shop = $this->shop;

        if ($shop === null) {
            return [];
        }

        return app(TenantContext::class)->run($shop, function () use ($limit): array {
            $lists = ShortlistWishlist::query()->latest('updated_at')->orderBy('id')->when($limit !== null, fn ($q) => $q->limit($limit))->get()
                ->filter(fn (ShortlistWishlist $list): bool => (array) $list->items !== []);
            $titles = CatalogProduct::query()->whereIn('external_id', $lists->pluck('items')->flatten()->unique()->map(fn ($id): string => (string) $id)->values())->pluck('title', 'external_id');

            return $lists->map(function (ShortlistWishlist $list) use ($titles): array {
                $ref = (string) $list->customer_ref;
                $phone = str_starts_with($ref, 'phone:') && is_string($list->phone) ? $list->phone : null;

                return [
                    'who' => match (true) {
                        $phone !== null => 'phone',
                        $ref !== '' => 'account',
                        default => 'browser',
                    },
                    'phone' => $phone === null ? null : ($list->marketing_consent ? $phone : Phone::masked($phone)),
                    'consent' => (bool) $list->marketing_consent,
                    'count' => count((array) $list->items),
                    'products' => array_values(array_filter(array_map(fn ($id): ?string => $titles[(string) $id] ?? null, (array) $list->items))),
                    'updated' => (string) $list->updated_at?->timezone('Asia/Jerusalem')->format('d/m/Y H:i'),
                    // The last visit that changed it, kept shortlist.visit_keep_days.
                    'ip' => $list->ip,
                    'device' => $list->device,
                    'browser' => $list->browser,
                    'os' => $list->os,
                    'referrer' => $list->referrer,
                    'session' => $list->session_id,
                ];
            })->values()->all();
        });
    }

    /** Every list as a spreadsheet, for the store manager: the number only where the shopper agreed. */
    public function export(): StreamedResponse
    {
        $rows = $this->lists(null);

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            // Excel reads the Hebrew right with the byte-order mark.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [__('shortlist::ui.col_updated'), __('shortlist::ui.col_who'), __('shortlist::ui.col_phone'), __('shortlist::ui.col_consent'), __('shortlist::ui.col_count'), __('shortlist::ui.col_products'), 'IP', __('shortlist::ui.col_device')]);
            foreach ($rows as $row) {
                fputcsv($out, [$row['updated'], __('shortlist::ui.who_'.$row['who']), $row['consent'] ? $row['phone'] : '', $row['consent'] ? __('shortlist::ui.yes') : '', $row['count'], implode(' | ', $row['products']), (string) $row['ip'], trim($row['device'].' '.$row['browser'].' '.$row['os'])]);
            }
            fclose($out);
        }, 'wishlists-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
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
