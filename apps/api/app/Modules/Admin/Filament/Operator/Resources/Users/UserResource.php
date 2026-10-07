<?php

namespace App\Modules\Admin\Filament\Operator\Resources\Users;

use App\Modules\Admin\Filament\Operator\Resources\Users\Pages\CreateUser;
use App\Modules\Admin\Filament\Operator\Resources\Users\Pages\EditUser;
use App\Modules\Admin\Filament\Operator\Resources\Users\Pages\ListUsers;
use App\Modules\Admin\Filament\Operator\Resources\Users\Schemas\UserForm;
use App\Modules\Admin\Filament\Operator\Resources\Users\Tables\UsersTable;
use App\Modules\Admin\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

final class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'system';

    protected static ?int $navigationSort = 40;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationLabel(): string
    {
        return __('admin::users.plural');
    }

    public static function getModelLabel(): string
    {
        return __('admin::users.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin::users.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
