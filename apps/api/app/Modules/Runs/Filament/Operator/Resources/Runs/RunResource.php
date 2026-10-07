<?php

namespace App\Modules\Runs\Filament\Operator\Resources\Runs;

use App\Modules\Runs\Filament\Operator\Resources\Runs\Pages\ListRuns;
use App\Modules\Runs\Filament\Operator\Resources\Runs\Pages\ViewRun;
use App\Modules\Runs\Filament\Operator\Resources\Runs\Schemas\RunInfolist;
use App\Modules\Runs\Filament\Operator\Resources\Runs\Tables\RunsTable;
use App\Modules\Runs\Models\Run;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

final class RunResource extends Resource
{
    protected static ?string $model = Run::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    protected static string|UnitEnum|null $navigationGroup = 'overview';

    protected static ?int $navigationSort = 30;

    public static function getNavigationLabel(): string
    {
        return __('runs::runs.plural');
    }

    public static function getModelLabel(): string
    {
        return __('runs::runs.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('runs::runs.plural');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return RunsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return RunInfolist::configure($schema);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRuns::route('/'),
            'view' => ViewRun::route('/{record}'),
        ];
    }
}
