<?php

namespace App\Modules\SearchSubscriptions\Filament\Merchant\Pages;

use App\Core\Facades\Settings;
use App\Core\Filament\EditsShopSettings;
use App\Core\Localization\Money;
use App\Modules\SearchSubscriptions\Support\Availability;
use App\Modules\SearchSubscriptions\Support\FollowedSearches;
use App\Modules\Tenancy\Models\Shop;
use BackedEnum;
use Carbon\CarbonInterface;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Cache;
use UnitEnum;

/**
 * Settings · Search Subscriptions, for the store's owner: on or off, where the offer shows in the search, the day the
 * email goes out, the logo at its head and where replies go; and "Searches people follow": the searches with the most
 * followers, and what the emails did in the last 30 days. Only for a store whose plan carries the feature
 * (Availability::offered); written for the owner, with no mechanics.
 */
class SearchSubscriptionsSettings extends Page
{
    use EditsShopSettings;

    /** The period the numbers cover, in days. */
    public const DAYS = 30;

    /** The searches listed under "Searches people follow". */
    public const TOP = 8;

    /** The latest sign-ups listed under "Who signed up". */
    public const PEOPLE = 50;

    public static string $sideGroup = 'store';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static string|UnitEnum|null $navigationGroup = 'settings';

    protected static ?int $navigationSort = 45;

    protected static ?string $slug = 'settings/search-subscriptions';

    protected string $view = 'search_subscriptions::merchant.settings';

    public static function canAccess(): bool
    {
        $shop = Filament::getTenant();

        return parent::canAccess() && $shop instanceof Shop && Availability::offered((string) $shop->id);
    }

    public static function getNavigationLabel(): string
    {
        return __('search_subscriptions::merchant.nav');
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
            'enabled' => 'feature:search_subscriptions.enabled',
            'placement' => 'setting:search_subscriptions.placement',
            'send_day' => 'setting:search_subscriptions.send_day',
            'logo_url' => 'setting:search_subscriptions.logo_url',
            'reply_to' => 'setting:search_subscriptions.reply_to',
        ];
    }

    protected function checkMore(): void
    {
        $logo = trim((string) ($this->data['logo_url'] ?? ''));
        if ($logo !== '' && ! preg_match('#^https://\S+$#i', $logo)) {
            $this->problems['logo_url'] = __('search_subscriptions::merchant.errors.logo');
        }
        $reply = trim((string) ($this->data['reply_to'] ?? ''));
        if ($reply !== '' && filter_var($reply, FILTER_VALIDATE_EMAIL) === false) {
            $this->problems['reply_to'] = __('search_subscriptions::merchant.errors.reply_to');
        }
        $this->data['logo_url'] = $logo;
        $this->data['reply_to'] = $reply;
    }

    /** @return list<array{query: string, people: int}> */
    public function followed(): array
    {
        return Cache::remember('search-subscriptions:top:'.$this->shopId().':'.$this->stamp(), 300, fn (): array => app(FollowedSearches::class)->top($this->shopId(), self::TOP));
    }

    /** @return list<array{email: string, query: string, status: string, at: CarbonInterface|null}> the latest sign-ups */
    public function people(): array
    {
        return app(FollowedSearches::class)->recent($this->shopId(), self::PEOPLE);
    }

    /** The subscriptions' last change, once a request: every count is kept under it, so none is stale. */
    private function stamp(): string
    {
        return $this->stamp ??= app(FollowedSearches::class)->stamp($this->shopId());
    }

    private ?string $stamp = null;

    /** @return array{following: int, signups: int, sent: int, opened: int, clicked: int, revenue: float} */
    public function numbers(): array
    {
        return Cache::remember('search-subscriptions:numbers:'.$this->shopId().':'.$this->stamp(), 300,
            fn (): array => app(FollowedSearches::class)->numbers($this->shopId(), now()->subDays(self::DAYS), now()));
    }

    /** The hour the email goes out, as the store reads a clock ("10:00"). */
    public function sendHour(): string
    {
        return sprintf('%02d:00', (int) Settings::get('search_subscriptions.send_hour', $this->shopId()));
    }

    public function money(float $amount): string
    {
        $shop = Filament::getTenant();

        return Money::total($amount, (string) ($shop instanceof Shop && $shop->currency ? $shop->currency : 'ILS'));
    }
}
