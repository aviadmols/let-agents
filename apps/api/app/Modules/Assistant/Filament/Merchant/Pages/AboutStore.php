<?php

namespace App\Modules\Assistant\Filament\Merchant\Pages;

use App\Core\Tenancy\LocksShopToPanelTenant;
use App\Modules\Assistant\Filament\Operator\Pages\AboutStore as OperatorAboutStore;
use UnitEnum;

/**
 * The knowledge centre, as the designer's panel draws it: what Lets knows about the store and uses
 * when it answers shoppers. A line the team adds (checked against the site), the site's pages, its
 * posts with what was learned from each, and what was found on the site. The shop is fixed.
 */
final class AboutStore extends OperatorAboutStore
{
    use LocksShopToPanelTenant;

    protected static string|UnitEnum|null $navigationGroup = 'knowledge';

    protected static ?int $navigationSort = 10;

    protected string $view = 'assistant::merchant.knowledge';

    public static function getNavigationLabel(): string
    {
        return __('assistant::ui.centre.title');
    }

    public function getTitle(): string
    {
        return __('assistant::ui.centre.title');
    }

    public function getSubheading(): ?string
    {
        return __('assistant::ui.centre.subheading');
    }

    public function mount(): void
    {
        // The trait also declares mount(); this one wins, and the parent still loads the text.
        $this->lockShopToTenant();
        parent::mount();
    }
}
