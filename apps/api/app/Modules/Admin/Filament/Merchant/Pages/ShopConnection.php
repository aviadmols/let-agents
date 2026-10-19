<?php

namespace App\Modules\Admin\Filament\Merchant\Pages;

use Filament\Pages\Page;
use UnitEnum;

/**
 * The old address of how the shop is connected. "Connection and plan" under settings (ConnectionSettings)
 * took its place; a link to this one opens that.
 */
final class ShopConnection extends Page
{
    protected static string|UnitEnum|null $navigationGroup = 'settings';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'connection';

    public function mount(): void
    {
        $this->redirect(ConnectionSettings::getUrl());
    }

    public function getTitle(): string
    {
        return __('admin::shop_settings.connection.title');
    }
}
