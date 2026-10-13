<?php

namespace App\Modules\Admin\Filament\Merchant\Pages;

use App\Modules\Admin\Contracts\ShopNotice;
use App\Modules\Admin\Support\Home;
use App\Modules\Tenancy\Models\Shop;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Everything the system wants the shop manager to know, in one place: what to do, what is not set
 * up yet, insights from the numbers, and reports of what ran. The same notices the home shows
 * five of, from every module (Admin\Contracts\HomeSource).
 */
final class ShopNotices extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBell;

    protected static string|UnitEnum|null $navigationGroup = null;

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'notices';

    protected string $view = 'admin::merchant.notices';

    /** all, or one of ShopNotice::KINDS */
    #[Url]
    public string $kind = 'all';

    public static function getNavigationLabel(): string
    {
        return __('admin::home.notices.title');
    }

    /** What waits for the manager: things to do and to set up. */
    public static function getNavigationBadge(): ?string
    {
        $shop = Filament::getTenant();

        if (! $shop instanceof Shop) {
            return null;
        }

        $waiting = count(array_filter(app(Home::class)->notices($shop), fn (ShopNotice $n): bool => in_array($n->kind, [ShopNotice::TODO, ShopNotice::SETUP], true)));

        return $waiting > 0 ? (string) $waiting : null;
    }

    public function getTitle(): string
    {
        return __('admin::home.notices.title');
    }

    public function getSubheading(): ?string
    {
        return __('admin::home.notices.subheading');
    }

    public function show(string $kind): void
    {
        $this->kind = in_array($kind, ShopNotice::KINDS, true) ? $kind : 'all';
    }

    /** @return list<ShopNotice> */
    public function all(): array
    {
        /** @var Shop $shop */
        $shop = Filament::getTenant();

        return app(Home::class)->notices($shop);
    }
}
