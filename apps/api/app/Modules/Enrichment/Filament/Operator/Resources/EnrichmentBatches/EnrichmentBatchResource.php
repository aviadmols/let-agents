<?php

namespace App\Modules\Enrichment\Filament\Operator\Resources\EnrichmentBatches;

use App\Modules\Enrichment\Enums\BatchStatus;
use App\Modules\Enrichment\Enums\TaskType;
use App\Modules\Enrichment\Filament\Operator\Resources\EnrichmentBatches\Pages\ListEnrichmentBatches;
use App\Modules\Enrichment\Filament\Operator\Resources\EnrichmentBatches\Pages\ViewEnrichmentBatch;
use App\Modules\Enrichment\Models\EnrichmentBatch;
use App\Modules\Enrichment\Support\UploadResultsAction;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Agent task files: what was asked, of which model, and what came back. The operator
 * downloads a file, runs it with any model, and uploads the answers here.
 */
final class EnrichmentBatchResource extends Resource
{
    protected static ?string $model = EnrichmentBatch::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|UnitEnum|null $navigationGroup = 'content';

    protected static ?int $navigationSort = 80;

    protected static ?string $slug = 'enrichment/tasks';

    public static function getNavigationLabel(): string
    {
        return __('enrichment::ui.batches.plural');
    }

    public static function getModelLabel(): string
    {
        return __('enrichment::ui.batches.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('enrichment::ui.batches.plural');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['shop', 'vocabulary']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label(__('enrichment::ui.fields.created_at'))->since()->dateTimeTooltip()->sortable(),
                TextColumn::make('task')
                    ->label(__('enrichment::ui.fields.task'))
                    ->badge()
                    ->formatStateUsing(fn (EnrichmentBatch $record): string => $record->task->label().($record->review_tier > 0 ? ' · '.__('enrichment::ui.fields.tier_short', ['tier' => $record->review_tier]) : '')),
                TextColumn::make('shop.name')->label(__('enrichment::ui.fields.shop'))->toggleable(),
                TextColumn::make('status')
                    ->label(__('enrichment::ui.fields.status'))
                    ->badge()
                    ->color(fn (BatchStatus $state): string => $state->color())
                    ->formatStateUsing(fn (BatchStatus $state): string => $state->label()),
                TextColumn::make('request_count')->label(__('enrichment::ui.fields.requests'))->numeric(),
                TextColumn::make('result_count')->label(__('enrichment::ui.fields.answers'))->numeric(),
                TextColumn::make('accepted_count')->label(__('enrichment::ui.fields.facts_saved'))->numeric(),
                TextColumn::make('rejected_count')->label(__('enrichment::ui.fields.problems'))->numeric(),
                TextColumn::make('model')->label(__('enrichment::ui.fields.model'))->fontFamily(FontFamily::Mono)->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('task')->label(__('enrichment::ui.fields.task'))->options(TaskType::options()),
                SelectFilter::make('status')->label(__('enrichment::ui.fields.status'))->options(BatchStatus::options()),
                SelectFilter::make('shop')->label(__('enrichment::ui.fields.shop'))->relationship('shop', 'name')->preload(),
            ])
            ->recordActions([
                Action::make('download')
                    ->label(__('enrichment::ui.actions.download'))
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->url(fn (EnrichmentBatch $record): string => route('enrichment.batches.download', ['batch' => $record->id])),
                UploadResultsAction::make(),
                ViewAction::make(),
            ])
            ->emptyStateHeading(__('enrichment::ui.batches.empty_heading'))
            ->emptyStateDescription(__('enrichment::ui.batches.empty_description'));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('enrichment::ui.batches.singular'))
                ->columns(4)
                ->schema([
                    TextEntry::make('task')->label(__('enrichment::ui.fields.task'))->formatStateUsing(fn (EnrichmentBatch $record): string => $record->task->label()),
                    TextEntry::make('shop.name')->label(__('enrichment::ui.fields.shop')),
                    TextEntry::make('status')->label(__('enrichment::ui.fields.status'))->badge()
                        ->color(fn (BatchStatus $state): string => $state->color())
                        ->formatStateUsing(fn (BatchStatus $state): string => $state->label()),
                    TextEntry::make('vocabulary_id')->label(__('enrichment::ui.fields.vocabulary'))
                        ->formatStateUsing(fn (EnrichmentBatch $record): string => $record->vocabulary?->title() ?? '-')
                        ->placeholder('-'),
                    TextEntry::make('request_count')->label(__('enrichment::ui.fields.requests')),
                    TextEntry::make('result_count')->label(__('enrichment::ui.fields.answers')),
                    TextEntry::make('accepted_count')->label(__('enrichment::ui.fields.facts_saved')),
                    TextEntry::make('rejected_count')->label(__('enrichment::ui.fields.problems')),
                    TextEntry::make('model')->label(__('enrichment::ui.fields.model'))->fontFamily(FontFamily::Mono)->placeholder('-'),
                    TextEntry::make('suggested')->label(__('enrichment::ui.fields.suggested_model'))->fontFamily(FontFamily::Mono)
                        ->state(fn (EnrichmentBatch $record): string => $record->task->suggestedModel($record->review_tier)),
                    TextEntry::make('prompt_version')->label(__('enrichment::ui.fields.prompt'))
                        ->formatStateUsing(fn (EnrichmentBatch $record): string => $record->prompt_key.' v'.$record->prompt_version),
                    TextEntry::make('created_at')->label(__('enrichment::ui.fields.created_at'))->dateTime(),
                ]),
            Section::make(__('enrichment::ui.fields.system_prompt'))
                ->collapsed()
                ->schema([
                    TextEntry::make('system_prompt')
                        ->hiddenLabel()
                        ->label(__('enrichment::ui.fields.system_prompt'))
                        ->fontFamily(FontFamily::Mono)
                        ->extraAttributes(['dir' => 'ltr', 'style' => 'white-space: pre-wrap']),
                ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEnrichmentBatches::route('/'),
            'view' => ViewEnrichmentBatch::route('/{record}'),
        ];
    }
}
