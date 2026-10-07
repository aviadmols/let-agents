<?php

namespace App\Modules\Tenancy\Filament\Operator\Resources\Shops;

use App\Modules\Tenancy\Filament\Operator\Resources\Shops\Pages\CreateShop;
use App\Modules\Tenancy\Filament\Operator\Resources\Shops\Pages\EditShop;
use App\Modules\Tenancy\Filament\Operator\Resources\Shops\Pages\ListShops;
use App\Modules\Tenancy\Filament\Operator\Resources\Shops\RelationManagers\ApiKeysRelationManager;
use App\Modules\Tenancy\Filament\Operator\Resources\Shops\Schemas\ShopForm;
use App\Modules\Tenancy\Filament\Operator\Resources\Shops\Tables\ShopsTable;
use App\Modules\Tenancy\Models\Shop;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

final class ShopResource extends Resource
{
    protected static ?string $model = Shop::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static string|UnitEnum|null $navigationGroup = 'shops';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationLabel(): string
    {
        return __('tenancy::shops.plural');
    }

    public static function getModelLabel(): string
    {
        return __('tenancy::shops.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('tenancy::shops.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return ShopForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ShopsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ApiKeysRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListShops::route('/'),
            'create' => CreateShop::route('/create'),
            'edit' => EditShop::route('/{record}/edit'),
        ];
    }
}
