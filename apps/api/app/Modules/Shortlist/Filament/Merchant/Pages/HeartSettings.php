<?php

namespace App\Modules\Shortlist\Filament\Merchant\Pages;

use App\Core\Facades\Settings;
use App\Core\Filament\EditsShopSettings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Shortlist\Actions\DetectProductCards;
use App\Modules\Tenancy\Enums\ShopPlatform;
use App\Modules\Tenancy\Models\Shop;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\RateLimiter;
use UnitEnum;

/**
 * Settings · The heart and the wishlist, as the designer's panel lays it out: where the heart appears,
 * where it sits on a card, what a click does, keeping the list by phone; and finding the theme's
 * product card from one of the shop's own category pages (DetectProductCards), which the heart script
 * then uses on every category page (shortlist.card_selector, sent in the shortlist config).
 */
class HeartSettings extends Page
{
    use EditsShopSettings;

    /** Detections a shop may run in a minute: each reads a page of its site. */
    private const DETECTIONS_PER_MINUTE = 6;

    public static string $sideGroup = 'shoppers';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;

    protected static string|UnitEnum|null $navigationGroup = 'settings';

    protected static ?int $navigationSort = 60;

    protected static ?string $slug = 'settings/heart';

    protected string $view = 'shortlist::merchant.settings.heart';

    /** The category page to read. */
    public string $detectUrl = '';

    /** @var array{selector: string, count: int, sample: array{title: ?string, image: ?string}}|null what the last detection found */
    public ?array $detected = null;

    public ?string $detectError = null;

    public static function getNavigationLabel(): string
    {
        return __('shortlist::shop_settings.heart.title');
    }

    public function getTitle(): string
    {
        return __('admin::shop_settings.title');
    }

    public function getSubheading(): ?string
    {
        return __('admin::shop_settings.subheading');
    }

    protected function fields(): array
    {
        return [
            'wishlist' => 'feature:shortlist.wishlist',
            'heart_on_product' => 'setting:shortlist.heart_on_product',
            'heart_on_cards' => 'setting:shortlist.heart_on_cards',
            'heart_in_results' => 'setting:shortlist.heart_in_results',
            'heart_place' => 'setting:shortlist.heart_place',
            'heart_click' => 'setting:shortlist.heart_click',
            'wishlist_phone' => 'feature:shortlist.wishlist_phone',
        ];
    }

    public function detect(DetectProductCards $cards): void
    {
        $this->detected = null;
        $this->detectError = null;

        if (trim($this->detectUrl) === '') {
            $this->detectError = __('shortlist::shop_settings.heart.errors.empty');

            return;
        }

        $key = 'shortlist:detect-cards:'.$this->shopId();
        if (RateLimiter::tooManyAttempts($key, self::DETECTIONS_PER_MINUTE)) {
            $this->detectError = __('shortlist::shop_settings.heart.errors.too_many');

            return;
        }
        RateLimiter::hit($key, 60);

        $found = $cards->fromUrl($this->detectUrl, $this->hosts(), $this->platform() === ShopPlatform::Shopify->value);

        if (isset($found['error'])) {
            $this->detectError = __('shortlist::shop_settings.heart.errors.'.$found['error'], ['domain' => $this->hosts()[0] ?? '']);

            return;
        }

        $this->detected = $found;
    }

    /** "Looks good, turn it on": the heart script finds the cards by this selector on every category page. */
    public function activate(): void
    {
        if ($this->detected === null) {
            return;
        }

        $shop = $this->shopId();
        Settings::set('shortlist.card_selector', mb_substr($this->detected['selector'], 0, 300), $shop);
        // A heart on the cards is the point of finding them.
        if (! Settings::get('shortlist.heart_on_cards', $shop)) {
            Settings::clear('shortlist.heart_on_cards', $shop);
            $this->data['heart_on_cards'] = (bool) Settings::get('shortlist.heart_on_cards', $shop);
        }

        $this->detected = null;
        $this->detectUrl = '';
        Notification::make()->success()->title(__('shortlist::shop_settings.heart.activated'))->send();
    }

    /** "Not this card": the finding is dropped, and a selector saved before goes with it. */
    public function reject(): void
    {
        if ($this->detected === null) {
            Settings::clear('shortlist.card_selector', $this->shopId());
            Notification::make()->success()->title(__('shortlist::shop_settings.heart.cleared'))->send();
        }

        $this->detected = null;
    }

    /** The selector the heart script uses now, or none. */
    public function activeSelector(): string
    {
        return (string) Settings::get('shortlist.card_selector', $this->shopId());
    }

    public function platform(): string
    {
        $shop = Filament::getTenant();

        return $shop instanceof Shop && $shop->platform instanceof ShopPlatform ? $shop->platform->value : ShopPlatform::WooCommerce->value;
    }

    public function placeholder(): string
    {
        return ($this->hosts()[0] ?? 'shop.example.com').($this->platform() === ShopPlatform::Shopify->value ? '/collections/…' : '/product-category/…');
    }

    /** @return list<string> the shop's own hosts: the connected site and the shop's domain */
    private function hosts(): array
    {
        $shop = Filament::getTenant();
        $connection = app(TenantContext::class)->run($this->shopId(), fn (): ?StoreConnection => StoreConnection::query()->first());
        $hosts = $connection?->allowedHosts() ?? [];

        if ($shop instanceof Shop && is_string($shop->domain) && $shop->domain !== '') {
            $hosts[] = strtolower((string) preg_replace('/^www\./i', '', $shop->domain));
        }

        return array_values(array_unique($hosts));
    }
}
