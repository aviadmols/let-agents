<?php

namespace App\Modules\TryOn\Filament\Merchant\Pages;

use App\Core\Facades\Features;
use App\Core\Tenancy\LocksShopToPanelTenant;
use App\Core\Tenancy\TenantContext;
use App\Modules\TryOn\Models\TryOnJob;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * How the shop's shoppers used Try-On this month, in counts. Nothing of how a picture is made, what
 * it cost or the shoppers' photos: those are the operator's (docs/try-on.md §9).
 */
final class TryOnSummary extends Page
{
    use LocksShopToPanelTenant;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|UnitEnum|null $navigationGroup = 'on_page';

    protected static ?int $navigationSort = 60;

    protected static ?string $slug = 'try-on';

    protected string $view = 'try_on::merchant.summary';

    public static function getNavigationLabel(): string
    {
        return __('try_on::try_on.merchant.title');
    }

    public function getTitle(): string
    {
        return __('try_on::try_on.merchant.title');
    }

    public function getSubheading(): ?string
    {
        return __('try_on::try_on.merchant.subheading');
    }

    /** @return array{on: bool, tries: int, ready: int} */
    public function counts(): array
    {
        $shop = (string) app(TenantContext::class)->id();

        return app(TenantContext::class)->run($shop, function () use ($shop): array {
            $month = TryOnJob::query()->where('created_at', '>=', now()->startOfMonth());

            return ['on' => Features::enabled('try_on.enabled', $shop), 'tries' => (clone $month)->count(), 'ready' => $month->where('status', TryOnJob::READY)->count()];
        });
    }
}
