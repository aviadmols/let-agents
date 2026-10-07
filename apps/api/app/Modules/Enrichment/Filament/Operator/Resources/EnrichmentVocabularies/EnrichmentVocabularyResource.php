<?php

namespace App\Modules\Enrichment\Filament\Operator\Resources\EnrichmentVocabularies;

use App\Core\Tenancy\NeedsShopContext;
use App\Modules\Enrichment\Filament\Operator\Resources\EnrichmentVocabularies\Pages\ListEnrichmentVocabularies;
use App\Modules\Enrichment\Filament\Operator\Resources\EnrichmentVocabularies\Pages\ViewEnrichmentVocabulary;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Enrichment\Prompts\VocabularyText;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** What agents may say about each branch of each shop's catalog. */
final class EnrichmentVocabularyResource extends Resource
{
    use NeedsShopContext;

    protected static ?string $model = EnrichmentVocabulary::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string|UnitEnum|null $navigationGroup = 'content';

    protected static ?int $navigationSort = 70;

    protected static ?string $slug = 'enrichment/vocabularies';

    public static function getNavigationLabel(): string
    {
        return __('enrichment::ui.vocabularies.plural');
    }

    public static function getModelLabel(): string
    {
        return __('enrichment::ui.vocabularies.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('enrichment::ui.vocabularies.plural');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('shop'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('key')
                    ->label(__('enrichment::ui.fields.name'))
                    ->formatStateUsing(fn (EnrichmentVocabulary $record): string => $record->definition()->name(app()->getLocale()))
                    ->description(fn (EnrichmentVocabulary $record): string => $record->key.' · v'.$record->version),
                TextColumn::make('shop.name')->label(__('enrichment::ui.fields.shop')),
                TextColumn::make('counts')
                    ->label(__('enrichment::ui.fields.contents'))
                    ->state(fn (EnrichmentVocabulary $record): string => __('enrichment::ui.vocabularies.counts', [
                        'types' => count($record->definition()->productTypes()),
                        'attributes' => count($record->definition()->attributes()),
                        'tags' => count($record->definition()->tags()),
                    ])),
                IconColumn::make('active')->label(__('enrichment::ui.fields.active'))->boolean(),
                TextColumn::make('author')->label(__('enrichment::ui.fields.author'))->placeholder('-'),
                TextColumn::make('created_at')->label(__('enrichment::ui.fields.created_at'))->since()->dateTimeTooltip(),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('enrichment::ui.vocabularies.as_model_sees_it'))
                ->description(__('enrichment::ui.vocabularies.as_model_sees_it_help'))
                ->schema([
                    TextEntry::make('rendered')
                        ->hiddenLabel()
                        ->label(__('enrichment::ui.vocabularies.as_model_sees_it'))
                        ->state(fn (EnrichmentVocabulary $record): string => VocabularyText::render($record->definition()))
                        ->extraAttributes(['style' => 'white-space: pre-wrap']),
                ]),
            Section::make(__('enrichment::ui.vocabularies.definition'))
                ->collapsed()
                ->schema([
                    TextEntry::make('definition')
                        ->hiddenLabel()
                        ->label(__('enrichment::ui.vocabularies.definition'))
                        ->state(fn (EnrichmentVocabulary $record): string => (string) json_encode($record->definition, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
                        ->fontFamily(FontFamily::Mono)
                        ->extraAttributes(['dir' => 'ltr', 'style' => 'white-space: pre-wrap']),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEnrichmentVocabularies::route('/'),
            'view' => ViewEnrichmentVocabulary::route('/{record}'),
        ];
    }
}
