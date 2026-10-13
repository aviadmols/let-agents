<?php

namespace App\Modules\Shortlist\Filament\Merchant\Pages;

use App\Core\Tenancy\LocksShopToPanelTenant;
use App\Modules\Shortlist\Filament\Operator\Pages\Shortlists as OperatorShortlists;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** The shop's wishlist on a screen of its own: the operator's screen, the shop fixed, only this part. */
final class Wishlist extends OperatorShortlists
{
    use LocksShopToPanelTenant;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;

    protected static string|UnitEnum|null $navigationGroup = 'shoppers';

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'wishlist';

    public static function getNavigationLabel(): string
    {
        return __('shortlist::ui.wishlist_title');
    }

    public function getTitle(): string
    {
        return __('shortlist::ui.wishlist_title');
    }

    public function getSubheading(): ?string
    {
        return __('shortlist::ui.wishlist_subheading');
    }

    public function part(): ?string
    {
        return 'wishlist';
    }

    public function mount(): void
    {
        // The trait also declares mount(); this one wins, and the parent still loads the values.
        $this->lockShopToTenant();
        parent::mount();
    }
}
