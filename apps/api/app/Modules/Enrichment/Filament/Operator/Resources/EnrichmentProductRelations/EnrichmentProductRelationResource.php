<?php

namespace App\Modules\Enrichment\Filament\Operator\Resources\EnrichmentProductRelations;

use App\Core\Tenancy\NeedsShopContext;
use App\Modules\Enrichment\Enums\RelationKind;
use App\Modules\Enrichment\Filament\Operator\Resources\EnrichmentProductRelations\Pages\ListEnrichmentProductRelations;
use App\Modules\Enrichment\Models\EnrichmentProductRelation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Lang;
use UnitEnum;

/** What shows next to each product, and why: complements, other sizes, alternatives. */
final class EnrichmentProductRelationResource extends Resource
{
    use NeedsShopContext;

    protected static ?string $model = EnrichmentProductRelation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquare2Stack;

    protected static string|UnitEnum|null $navigationGroup = 'content';

    protected static ?int $navigationSort = 50;

    protected static ?string $slug = 'enrichment/relations';

    public static function getNavigationLabel(): string
    {
        return __('enrichment::ui.relations.plural');
    }

    public static function getModelLabel(): string
    {
        return __('enrichment::ui.relations.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('enrichment::ui.relations.plural');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['product', 'related']))
            ->defaultGroup(Group::make('product.title')->label(__('enrichment::ui.fields.product'))->collapsible())
            ->defaultSort('score', 'desc')
            ->columns([
                TextColumn::make('kind')->label(__('enrichment::ui.fields.kind'))->badge()->formatStateUsing(fn (RelationKind $state): string => $state->label()),
                ImageColumn::make('related.image_url')->label('')->square()->imageSize(40),
                TextColumn::make('related.title')->label(__('enrichment::ui.relations.related'))->wrap()->searchable()->width('35%'),
                TextColumn::make('related.price')->label(__('catalog::catalog.fields.price'))->money('ILS'),
                TextColumn::make('source')->label(__('enrichment::ui.fields.source'))->formatStateUsing(fn (string $state): string => self::sourceLabel($state)),
                TextColumn::make('score')->label(__('enrichment::ui.relations.score'))->numeric()->sortable(),
                TextColumn::make('reasons')
                    ->label(__('enrichment::ui.fields.why'))
                    ->state(fn (EnrichmentProductRelation $record): array => self::reasonLines($record->reasons))
                    ->listWithLineBreaks(),
            ])
            ->filters([
                SelectFilter::make('shop')->label(__('enrichment::ui.fields.shop'))->relationship('shop', 'name')->preload(),
                SelectFilter::make('kind')->label(__('enrichment::ui.fields.kind'))->options(RelationKind::options()),
                SelectFilter::make('source')->label(__('enrichment::ui.fields.source'))
                    ->options(fn (): array => EnrichmentProductRelation::query()->distinct()->pluck('source')->mapWithKeys(fn (string $s): array => [$s => self::sourceLabel($s)])->all()),
            ])
            ->emptyStateHeading(__('enrichment::ui.relations.empty_heading'))
            ->emptyStateDescription(__('enrichment::ui.relations.empty_description'));
    }

    public static function sourceLabel(string $source): string
    {
        return Lang::has("enrichment::ui.relations.sources.{$source}") ? __("enrichment::ui.relations.sources.{$source}") : $source;
    }

    /**
     * @param  array<string, mixed>  $reasons
     * @return list<string>
     */
    public static function reasonLines(array $reasons): array
    {
        $lines = [];

        foreach ($reasons as $key => $value) {
            $lines[] = $key.': '.(is_array($value) ? implode(', ', array_map('strval', $value)) : (is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value));
        }

        return $lines;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEnrichmentProductRelations::route('/'),
        ];
    }
}
