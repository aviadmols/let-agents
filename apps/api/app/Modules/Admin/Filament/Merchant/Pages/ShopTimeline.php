<?php

namespace App\Modules\Admin\Filament\Merchant\Pages;

use App\Modules\Admin\Contracts\TimelineEntry;
use App\Modules\Admin\Panels\MerchantNavigation;
use App\Modules\Admin\Support\Timeline;
use App\Modules\Tenancy\Models\Shop;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * The shop's timeline, as the designer's panel draws it: everything that happened in the account, by
 * day: the store's scans, products and posts that came in, what the team answered and decided, leads,
 * users. The same sources as the operator's timeline, without what is the operator's: no cost, no model,
 * no failure detail, no mechanism (TimelineEntry::$forShop). Each entry opens the screen it belongs to.
 */
final class ShopTimeline extends Page
{
    /** The kinds the shop filters by, in the design's order. */
    public const KINDS = [TimelineEntry::SCAN, TimelineEntry::CATALOG, TimelineEntry::KNOWLEDGE, TimelineEntry::USER, TimelineEntry::INSIGHT, TimelineEntry::LEAD];

    /** Each kind opens this screen of the panel (MerchantNavigation::SECTIONS). */
    private const GOES_TO = [
        TimelineEntry::SCAN => 'scan',
        TimelineEntry::CATALOG => 'scan',
        TimelineEntry::KNOWLEDGE => 'knowledge',
        TimelineEntry::USER => 'inbox',
        TimelineEntry::INSIGHT => 'inbox',
        TimelineEntry::LEAD => 'inbox',
    ];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'timeline';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'timeline';

    protected string $view = 'admin::merchant.timeline';

    #[Url]
    public string $kind = '';

    /** 30 days, and 30 more each time the manager asks. */
    #[Url]
    public int $days = 30;

    public static function getNavigationLabel(): string
    {
        return __('admin::timeline.title');
    }

    public function getTitle(): string
    {
        return __('admin::timeline.title');
    }

    public function getSubheading(): ?string
    {
        return __('admin::timeline.shop_subheading', ['days' => $this->days]);
    }

    public function pick(string $kind): void
    {
        $this->kind = in_array($kind, self::KINDS, true) ? $kind : '';
    }

    public function more(): void
    {
        $this->days = min(365, $this->days + 30);
    }

    /** @return list<TimelineEntry> what the shop sees of the period, before the kind filter */
    public function all(): array
    {
        /** @var Shop $shop */
        $shop = Filament::getTenant();

        return array_values(array_filter(
            app(Timeline::class)->entries($shop, $this->days),
            fn (TimelineEntry $e): bool => $e->forShop && in_array($e->kind, self::KINDS, true),
        ));
    }

    /** @return array<string, list<TimelineEntry>> */
    public function byDay(): array
    {
        $all = $this->all();

        return Timeline::byDay($this->kind === '' ? $all : array_values(array_filter($all, fn (TimelineEntry $e): bool => $e->kind === $this->kind)));
    }

    /** Where an entry of this kind is followed up in the shop's panel. */
    public function link(string $kind): ?string
    {
        $pages = MerchantNavigation::pages(self::GOES_TO[$kind] ?? null);

        return $pages === [] ? null : $pages[0]::getUrl();
    }
}
