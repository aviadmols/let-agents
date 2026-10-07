<?php

namespace App\Modules\Ai\Filament\Operator\Resources\AiProviders;

use App\Modules\Ai\Filament\Operator\Resources\AiProviders\Pages\CreateAiProvider;
use App\Modules\Ai\Filament\Operator\Resources\AiProviders\Pages\EditAiProvider;
use App\Modules\Ai\Filament\Operator\Resources\AiProviders\Pages\ListAiProviders;
use App\Modules\Ai\Filament\Operator\Resources\AiProviders\Schemas\AiProviderForm;
use App\Modules\Ai\Filament\Operator\Resources\AiProviders\Tables\AiProvidersTable;
use App\Modules\Ai\Models\AiProvider;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

final class AiProviderResource extends Resource
{
    protected static ?string $model = AiProvider::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|UnitEnum|null $navigationGroup = 'system';

    protected static ?int $navigationSort = 10;

    public static function getNavigationLabel(): string
    {
        return __('ai::providers.plural');
    }

    public static function getModelLabel(): string
    {
        return __('ai::providers.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ai::providers.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return AiProviderForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AiProvidersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAiProviders::route('/'),
            'create' => CreateAiProvider::route('/create'),
            'edit' => EditAiProvider::route('/{record}/edit'),
        ];
    }
}
