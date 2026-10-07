<?php

namespace App\Modules\Catalog\Filament\Operator\Resources\CatalogContents;

use App\Core\Tenancy\NeedsShopContext;
use App\Modules\Catalog\Filament\Operator\Resources\CatalogContents\Pages\ListCatalogContents;
use App\Modules\Catalog\Filament\Operator\Resources\CatalogContents\Pages\ViewCatalogContent;
use App\Modules\Catalog\Models\CatalogContent;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Articles and guides the store shares with Let Agents. Read-only. */
final class CatalogContentResource extends Resource
{
    use NeedsShopContext;

    protected static ?string $model = CatalogContent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNewspaper;

    protected static string|UnitEnum|null $navigationGroup = 'content';

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'catalog/content';

    public static function getNavigationLabel(): string
    {
        return __('catalog::catalog.content.plural');
    }

    public static function getModelLabel(): string
    {
        return __('catalog::catalog.content.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('catalog::catalog.content.plural');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('shop')->whereNull('removed_at'))
            ->defaultSort('source_updated_at', 'desc')
            ->columns([
                ImageColumn::make('image_url')->label(__('catalog::catalog.fields.image'))->square()->imageSize(44),
                TextColumn::make('title')
                    ->label(__('catalog::catalog.fields.title'))
                    ->searchable()
                    ->wrap()
                    ->description(fn (CatalogContent $record): string => mb_strimwidth((string) $record->excerpt, 0, 140, '…')),
                TextColumn::make('shop.name')->label(__('catalog::catalog.fields.shop'))->toggleable(),
                TextColumn::make('type')->label(__('catalog::catalog.fields.type'))->badge(),
                TextColumn::make('source_updated_at')
                    ->label(__('catalog::catalog.fields.updated_in_store'))
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('shop')->label(__('catalog::catalog.fields.shop'))->relationship('shop', 'name')->preload(),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('catalog::catalog.sections.content'))
                ->columns(3)
                ->schema([
                    ImageEntry::make('image_url')->label(__('catalog::catalog.fields.image'))->imageHeight(120)->placeholder('-'),
                    TextEntry::make('shop.name')->label(__('catalog::catalog.fields.shop')),
                    TextEntry::make('url')
                        ->label(__('catalog::catalog.fields.url'))
                        ->url(fn (CatalogContent $record): ?string => $record->url, shouldOpenInNewTab: true)
                        ->formatStateUsing(fn (): string => __('catalog::catalog.actions.open_in_store'))
                        ->placeholder('-'),
                    TextEntry::make('excerpt')->label(__('catalog::catalog.fields.excerpt'))->columnSpanFull()->placeholder('-'),
                    TextEntry::make('body')
                        ->label(__('catalog::catalog.fields.body'))
                        ->columnSpanFull()
                        ->extraAttributes(['style' => 'white-space: pre-line'])
                        ->placeholder('-'),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCatalogContents::route('/'),
            'view' => ViewCatalogContent::route('/{record}'),
        ];
    }
}
