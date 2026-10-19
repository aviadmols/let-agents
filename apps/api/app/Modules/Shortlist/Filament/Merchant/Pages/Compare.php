<?php

namespace App\Modules\Shortlist\Filament\Merchant\Pages;

use App\Core\Tenancy\LocksShopToPanelTenant;
use App\Modules\Shortlist\Filament\Operator\Pages\Shortlists as OperatorShortlists;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** The shop's compare on a screen of its own: the operator's screen, the shop fixed, only this part. */
final class Compare extends OperatorShortlists
{
    use LocksShopToPanelTenant;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'settings';

    protected static ?int $navigationSort = 50;

    protected static ?string $slug = 'compare';

    /** Out of the settings menu: compare is switched in "The search window"; what shoppers compared opens from the home figures (ShortlistHome). */
    protected static bool $shouldRegisterNavigation = false;

    public static function getNavigationLabel(): string
    {
        return __('shortlist::ui.compare_title');
    }

    public function getTitle(): string
    {
        return __('shortlist::ui.compare_title');
    }

    public function getSubheading(): ?string
    {
        return __('shortlist::ui.compare_subheading');
    }

    public function part(): ?string
    {
        return 'compare';
    }

    public function mount(): void
    {
        // The trait also declares mount(); this one wins, and the parent still loads the values.
        $this->lockShopToTenant();
        parent::mount();
    }
}
