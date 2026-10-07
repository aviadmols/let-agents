<?php

namespace App\Modules\Catalog\Filament\Operator\Resources\CatalogProducts;

use App\Core\Tenancy\NeedsShopContext;
use App\Modules\Catalog\Filament\Operator\Resources\CatalogProducts\Pages\ListCatalogProducts;
use App\Modules\Catalog\Filament\Operator\Resources\CatalogProducts\Pages\ViewCatalogProduct;
use App\Modules\Catalog\Filament\Operator\Resources\CatalogProducts\Schemas\CatalogProductInfolist;
use App\Modules\Catalog\Filament\Operator\Resources\CatalogProducts\Tables\CatalogProductsTable;
use App\Modules\Catalog\Models\CatalogProduct;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/** What Let Agents read from each store. Read-only: the store is the source of truth. */
final class CatalogProductResource extends Resource
{
    use NeedsShopContext;

    protected static ?string $model = CatalogProduct::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static string|UnitEnum|null $navigationGroup = 'content';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'catalog/products';

    public static function getNavigationLabel(): string
    {
        return __('catalog::catalog.products.plural');
    }

    public static function getModelLabel(): string
    {
        return __('catalog::catalog.products.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('catalog::catalog.products.plural');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return CatalogProductsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CatalogProductInfolist::configure($schema);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCatalogProducts::route('/'),
            'view' => ViewCatalogProduct::route('/{record}'),
        ];
    }
}
