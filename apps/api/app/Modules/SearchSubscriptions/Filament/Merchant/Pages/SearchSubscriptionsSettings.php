<?php

namespace App\Modules\SearchSubscriptions\Filament\Merchant\Pages;

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
 * Settings · Search Subscriptions, for the store's owner: on or off, where the offer shows in the search, the day and
 * hour the email goes out (in the store's time), the logo at its head and where replies go; "Searches people follow":
 * the searches with the most followers, and what the emails did in the last 30 days; and "Who signed up", each with
 * what waits for their next email. Only for a store whose plan carries the feature (Availability::offered); written
 * for the owner, with no mechanics.
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
            'send_hour' => 'setting:search_subscriptions.send_hour',
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

    /** @return list<array{id: string, hash: string, email: string, query: string, status: string, at: CarbonInterface|null}> the latest sign-ups */
    public function people(): array
    {
        return app(FollowedSearches::class)->recent($this->shopId(), self::PEOPLE);
    }

    /**
     * What waits for the next email of the people shown, and when each was last emailed: kept under the subscriptions'
     * last change, the catalogue's and the emails', so it is worked out again only when one of them moved.
     *
     * @param  list<array{hash: string}>  $people
     * @return array{next: array<string, int>, last: array<string, string>}
     */
    public function upcoming(array $people): array
    {
        $hashes = array_values(array_unique(array_column($people, 'hash')));
        sort($hashes, SORT_STRING);
        $followed = app(FollowedSearches::class);
        $key = 'search-subscriptions:upcoming:'.$this->shopId().':'.md5($this->stamp().'|'.$followed->changes($this->shopId()).'|'.implode(',', $hashes));

        return Cache::remember($key, 600, fn (): array => $followed->upcoming($this->shopId(), $hashes));
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

    /** @return array<int, string> the hours the email may go out at, as the store reads a clock ("10:00") */
    public function hours(): array
    {
        return array_map(fn (int $h): string => sprintf('%02d:00', $h), array_combine(range(0, 23), range(0, 23)));
    }

    public function money(float $amount): string
    {
        $shop = Filament::getTenant();

        return Money::total($amount, (string) ($shop instanceof Shop && $shop->currency ? $shop->currency : 'ILS'));
    }
}
