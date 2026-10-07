<?php

namespace App\Modules\Connections\Filament\Operator\Resources\StoreConnections;

use App\Modules\Connections\Filament\Operator\Resources\StoreConnections\Pages\CreateStoreConnection;
use App\Modules\Connections\Filament\Operator\Resources\StoreConnections\Pages\EditStoreConnection;
use App\Modules\Connections\Filament\Operator\Resources\StoreConnections\Pages\ListStoreConnections;
use App\Modules\Connections\Filament\Operator\Resources\StoreConnections\Schemas\StoreConnectionForm;
use App\Modules\Connections\Filament\Operator\Resources\StoreConnections\Tables\StoreConnectionsTable;
use App\Modules\Connections\Models\StoreConnection;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

final class StoreConnectionResource extends Resource
{
    protected static ?string $model = StoreConnection::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;

    protected static string|UnitEnum|null $navigationGroup = 'shops';

    protected static ?int $navigationSort = 30;

    public static function getNavigationLabel(): string
    {
        return __('connections::connections.plural');
    }

    public static function getModelLabel(): string
    {
        return __('connections::connections.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('connections::connections.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return StoreConnectionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StoreConnectionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStoreConnections::route('/'),
            'create' => CreateStoreConnection::route('/create'),
            'edit' => EditStoreConnection::route('/{record}/edit'),
        ];
    }
}
