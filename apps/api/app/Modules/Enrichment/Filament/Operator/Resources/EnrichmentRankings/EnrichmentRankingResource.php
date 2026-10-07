<?php

namespace App\Modules\Enrichment\Filament\Operator\Resources\EnrichmentRankings;

use App\Core\Tenancy\NeedsShopContext;
use App\Modules\Enrichment\Filament\Operator\Resources\EnrichmentRankings\Pages\ListEnrichmentRankings;
use App\Modules\Enrichment\Models\EnrichmentRanking;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Enrichment\Scanning\Measurement;
use App\Modules\Enrichment\Vocabulary\VocabularyDefinition;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Superlatives computed in code, with the set each one was measured in. */
final class EnrichmentRankingResource extends Resource
{
    use NeedsShopContext;

    protected static ?string $model = EnrichmentRanking::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrophy;

    protected static string|UnitEnum|null $navigationGroup = 'content';

    protected static ?int $navigationSort = 40;

    protected static ?string $slug = 'enrichment/rankings';

    /** @var array<string, VocabularyDefinition|null> */
    private static array $vocabularies = [];

    public static function getNavigationLabel(): string
    {
        return __('enrichment::ui.rankings.plural');
    }

    public static function getModelLabel(): string
    {
        return __('enrichment::ui.rankings.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('enrichment::ui.rankings.plural');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('product'))
            ->defaultSort('set_key')
            ->columns([
                TextColumn::make('product.title')->label(__('enrichment::ui.fields.subject'))->wrap()->searchable()->width('30%'),
                TextColumn::make('metric')
                    ->label(__('enrichment::ui.fields.metric'))
                    ->formatStateUsing(fn (EnrichmentRanking $record): string => self::metricLabel($record))
                    ->description(fn (EnrichmentRanking $record): string => __('enrichment::ui.rankings.directions.'.$record->direction)),
                TextColumn::make('rank')
                    ->label(__('enrichment::ui.fields.rank'))
                    ->formatStateUsing(fn (EnrichmentRanking $record): string => __('enrichment::ui.rankings.rank_of', ['rank' => $record->rank, 'size' => $record->set_size]))
                    ->sortable(),
                IconColumn::make('tied')->label(__('enrichment::ui.fields.tied'))->boolean(),
                TextColumn::make('value')
                    ->label(__('enrichment::ui.fields.value'))
                    ->formatStateUsing(fn (EnrichmentRanking $record): string => $record->metric === 'price'
                        ? '₪'.number_format((float) $record->value, 2)
                        : Measurement::compact((float) $record->value).' '.$record->unit),
                TextColumn::make('set_facets')
                    ->label(__('enrichment::ui.fields.set'))
                    ->state(fn (EnrichmentRanking $record): string => self::setLabel($record))
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('metric')->label(__('enrichment::ui.fields.metric'))
                    ->options(fn (): array => EnrichmentRanking::query()->distinct()->pluck('metric')->mapWithKeys(fn (string $m): array => [$m => $m])->all()),
                SelectFilter::make('rank')->label(__('enrichment::ui.fields.rank'))->options([1 => '1', 2 => '2', 3 => '3']),
                SelectFilter::make('shop')->label(__('enrichment::ui.fields.shop'))->relationship('shop', 'name')->preload(),
            ])
            ->emptyStateHeading(__('enrichment::ui.rankings.empty_heading'))
            ->emptyStateDescription(__('enrichment::ui.rankings.empty_description'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEnrichmentRankings::route('/'),
        ];
    }

    private static function vocabulary(EnrichmentRanking $record): ?VocabularyDefinition
    {
        $key = explode('|', $record->set_key)[0];
        $cacheKey = $record->shop_id.'|'.$key;

        return self::$vocabularies[$cacheKey] ??= EnrichmentVocabulary::query()
            ->where('shop_id', $record->shop_id)->where('key', $key)->where('active', true)
            ->first()?->definition();
    }

    private static function metricLabel(EnrichmentRanking $record): string
    {
        if ($record->metric === 'price') {
            return __('enrichment::ui.rankings.price');
        }

        return self::vocabulary($record)?->label('attribute', $record->metric, app()->getLocale()) ?? $record->metric;
    }

    private static function setLabel(EnrichmentRanking $record): string
    {
        $vocabulary = self::vocabulary($record);
        $locale = app()->getLocale();
        $parts = [];

        foreach ($record->set_facets as $key => $value) {
            $parts[] = $key === 'type'
                ? ($vocabulary?->label('type', $value, $locale) ?? $value)
                : ($vocabulary?->label('attribute', $key, $locale, $value) ?? $value);
        }

        return implode(' · ', $parts);
    }
}
