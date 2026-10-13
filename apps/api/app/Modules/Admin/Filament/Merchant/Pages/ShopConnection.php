<?php

namespace App\Modules\Admin\Filament\Merchant\Pages;

use App\Modules\Tenancy\Enums\ShopPlatform;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use UnitEnum;

/** How the shop is connected, under settings: its status, platform, address and the plugin's last call. */
final class ShopConnection extends Page
{
    protected static string|UnitEnum|null $navigationGroup = 'settings';

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'connection';

    public static function getNavigationLabel(): string
    {
        return __('admin::home.connection');
    }

    public function getTitle(): string
    {
        return __('admin::home.connection');
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
                    // A Shopify store is connected by its app; keys and plugin calls are a plugin store's.
                    TextEntry::make('shopify_app')
                        ->label(__('admin::overview.connected_by'))
                        ->state(__('admin::overview.shopify_app'))
                        ->visible($shop->platform === ShopPlatform::Shopify),
                    TextEntry::make('active_keys')
                        ->visible($shop->platform !== ShopPlatform::Shopify)
                        ->label(__('tenancy::shops.fields.active_keys'))
                        ->state((string) $shop->apiKeys()->active()->count()),
                    TextEntry::make('last_api_call')
                        ->visible($shop->platform !== ShopPlatform::Shopify)
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
