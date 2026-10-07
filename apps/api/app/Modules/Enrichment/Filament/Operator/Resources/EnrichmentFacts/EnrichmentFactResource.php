<?php

namespace App\Modules\Enrichment\Filament\Operator\Resources\EnrichmentFacts;

use App\Core\Tenancy\NeedsShopContext;
use App\Modules\Enrichment\Actions\DecideFact;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Filament\Operator\Resources\EnrichmentFacts\Pages\ListEnrichmentFacts;
use App\Modules\Enrichment\Models\EnrichmentFact;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * Everything agents said about products and articles, with where each claim came from and who
 * checked it. The queue of claims waiting for a person is the default view.
 */
final class EnrichmentFactResource extends Resource
{
    use NeedsShopContext;

    protected static ?string $model = EnrichmentFact::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckBadge;

    protected static string|UnitEnum|null $navigationGroup = 'content';

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'enrichment/facts';

    public static function getNavigationLabel(): string
    {
        return __('enrichment::ui.facts.plural');
    }

    public static function getModelLabel(): string
    {
        return __('enrichment::ui.facts.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('enrichment::ui.facts.plural');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['product', 'content', 'vocabulary'])->where('status', '!=', FactStatus::Superseded))
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('subject')
                    ->label(__('enrichment::ui.fields.subject'))
                    ->state(fn (EnrichmentFact $record): string => $record->subjectTitle())
                    ->wrap()
                    ->width('24%'),
                TextColumn::make('key')
                    ->label(__('enrichment::ui.fields.claim'))
                    ->state(fn (EnrichmentFact $record): string => $record->keyLabel())
                    ->description(fn (EnrichmentFact $record): string => $record->valueLabel())
                    ->searchable(),
                TextColumn::make('quote')
                    ->label(__('enrichment::ui.fields.quote'))
                    ->limit(90)
                    ->tooltip(fn (EnrichmentFact $record): ?string => $record->quote)
                    ->wrap()
                    ->width('30%'),
                TextColumn::make('origin')
                    ->label(__('enrichment::ui.fields.origin'))
                    ->formatStateUsing(fn (EnrichmentFact $record): string => $record->origin->label())
                    ->toggleable(),
                TextColumn::make('status')
                    ->label(__('enrichment::ui.fields.status'))
                    ->badge()
                    ->color(fn (FactStatus $state): string => $state->color())
                    ->formatStateUsing(fn (FactStatus $state): string => $state->label())
                    ->description(fn (EnrichmentFact $record): ?string => $record->status_reason === null ? null : __("enrichment::ui.reasons.{$record->status_reason}")),
                TextColumn::make('review_verdict')
                    ->label(__('enrichment::ui.fields.verdict'))
                    ->formatStateUsing(fn (EnrichmentFact $record): string => $record->review_verdict?->label() ?? '-')
                    ->description(fn (EnrichmentFact $record): ?string => $record->review_model)
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('model')->label(__('enrichment::ui.fields.model'))->fontFamily(FontFamily::Mono)->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('enrichment::ui.fields.status'))->options(FactStatus::options())->multiple(),
                SelectFilter::make('kind')->label(__('enrichment::ui.fields.kind'))->options(FactKind::options()),
                SelectFilter::make('shop')->label(__('enrichment::ui.fields.shop'))->relationship('shop', 'name')->preload(),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label(__('enrichment::ui.actions.approve'))
                    ->icon(Heroicon::OutlinedCheck)
                    ->color('success')
                    ->visible(fn (EnrichmentFact $record): bool => $record->status !== FactStatus::Approved)
                    ->action(fn (EnrichmentFact $record) => app(DecideFact::class)->handle($record, true)),
                Action::make('reject')
                    ->label(__('enrichment::ui.actions.reject'))
                    ->icon(Heroicon::OutlinedXMark)
                    ->color('danger')
                    ->visible(fn (EnrichmentFact $record): bool => $record->status !== FactStatus::Rejected)
                    ->action(fn (EnrichmentFact $record) => app(DecideFact::class)->handle($record, false)),
            ])
            ->toolbarActions([
                BulkAction::make('approve_selected')
                    ->label(__('enrichment::ui.actions.approve_selected'))
                    ->icon(Heroicon::OutlinedCheck)
                    ->requiresConfirmation()
                    ->action(fn (Collection $records) => $records->each(fn (EnrichmentFact $f) => app(DecideFact::class)->handle($f, true))),
                BulkAction::make('reject_selected')
                    ->label(__('enrichment::ui.actions.reject_selected'))
                    ->icon(Heroicon::OutlinedXMark)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(fn (Collection $records) => $records->each(fn (EnrichmentFact $f) => app(DecideFact::class)->handle($f, false))),
            ])
            ->emptyStateHeading(__('enrichment::ui.facts.empty_heading'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEnrichmentFacts::route('/'),
        ];
    }
}
