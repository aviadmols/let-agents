<?php

namespace App\Modules\Admin\Filament\Merchant\Pages;

use App\Modules\Admin\Mail\WeeklySummaryMail;
use App\Modules\Admin\Support\WeeklySummary;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Filament\Pages\Page;

/**
 * The weekly email as the owner would get it this Monday, from Today's "See an example": the first sketch (8.11),
 * drawn with the store's real week and shown in a frame so its own styles stay its own. Nothing is sent from here.
 * Off the sidebar.
 */
final class WeeklyEmailPreview extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'weekly-email';

    protected string $view = 'admin::merchant.weekly-preview';

    public function getTitle(): string
    {
        return __('admin::weekly.preview.title');
    }

    public function getSubheading(): ?string
    {
        return __('admin::weekly.preview.sub');
    }

    public function html(): string
    {
        /** @var Shop $shop */
        $shop = Filament::getTenant();

        return (new WeeklySummaryMail(app(WeeklySummary::class)->of($shop)))->render();
    }
}
