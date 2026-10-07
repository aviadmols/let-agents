<?php

namespace App\Modules\Admin\Filament\Merchant\Pages;

use App\Modules\Tenancy\Models\Shop;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use UnitEnum;

/**
 * The merchant's home screen. For now: is the shop connected and is it live. Results,
 * approvals and learning status join it as their modules are built.
 */
final class ShopOverview extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static string|UnitEnum|null $navigationGroup = 'overview';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'overview';

    public static function getNavigationLabel(): string
    {
        return __('admin::overview.title');
    }

    public function getTitle(): string
    {
        return $this->shop()->name;
    }

    public function content(Schema $schema): Schema
    {
        $shop = $this->shop();
        $lastUsed = $shop->apiKeys()->active()->max('last_used_at');

        return $schema->components([
            Section::make(__('admin::overview.connection'))
                ->columns(3)
                ->schema([
                    TextEntry::make('status')
                        ->label(__('tenancy::shops.fields.status'))
                        ->state($shop->status->label())
                        ->badge()
                        ->color($shop->status->color()),
                    TextEntry::make('platform')
                        ->label(__('tenancy::shops.fields.platform'))
                        ->state($shop->platform->label()),
                    TextEntry::make('domain')
                        ->label(__('tenancy::shops.fields.domain'))
                        ->state($shop->domain),
                    TextEntry::make('content_locale')
                        ->label(__('tenancy::shops.fields.content_locale'))
                        ->state(__("tenancy::shops.locales.{$shop->content_locale}")),
                    TextEntry::make('active_keys')
                        ->label(__('tenancy::shops.fields.active_keys'))
                        ->state((string) $shop->apiKeys()->active()->count()),
                    TextEntry::make('last_api_call')
                        ->label(__('admin::overview.last_api_call'))
                        ->state($lastUsed === null ? __('admin::overview.never_connected') : Carbon::parse($lastUsed)->diffForHumans()),
                ]),
        ]);
    }

    private function shop(): Shop
    {
        /** @var Shop $shop */
        $shop = Filament::getTenant();

        return $shop;
    }
}
