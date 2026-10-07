<?php

namespace App\Modules\Enrichment\Filament\Operator\Resources\EnrichmentContentProducts;

use App\Core\Tenancy\NeedsShopContext;
use App\Modules\Enrichment\Filament\Operator\Resources\EnrichmentContentProducts\Pages\ListEnrichmentContentProducts;
use App\Modules\Enrichment\Models\EnrichmentContentProduct;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** The products a guide or post page can show, and why each one was chosen. */
final class EnrichmentContentProductResource extends Resource
{
    use NeedsShopContext;

    protected static ?string $model = EnrichmentContentProduct::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperClip;

    protected static string|UnitEnum|null $navigationGroup = 'content';

    protected static ?int $navigationSort = 60;

    protected static ?string $slug = 'enrichment/article-products';

    public static function getNavigationLabel(): string
    {
        return __('enrichment::ui.article_products.plural');
    }

    public static function getModelLabel(): string
    {
        return __('enrichment::ui.article_products.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('enrichment::ui.article_products.plural');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['content', 'product']))
            ->defaultGroup(Group::make('content.title')->label(__('enrichment::ui.fields.article'))->collapsible())
            ->defaultSort('rank')
            ->columns([
                TextColumn::make('rank')->label(__('enrichment::ui.fields.rank'))->numeric(),
                ImageColumn::make('product.image_url')->label('')->square()->imageSize(40),
                TextColumn::make('product.title')->label(__('enrichment::ui.fields.product'))->wrap()->searchable()->width('40%'),
                TextColumn::make('product.price')
                    ->label(__('catalog::catalog.fields.price'))
                    ->money('ILS'),
                TextColumn::make('reasons')
                    ->label(__('enrichment::ui.fields.why'))
                    ->state(fn (EnrichmentContentProduct $record): array => self::reasons($record))
                    ->listWithLineBreaks(),
            ])
            ->filters([
                SelectFilter::make('shop')->label(__('enrichment::ui.fields.shop'))->relationship('shop', 'name')->preload(),
            ])
            ->emptyStateHeading(__('enrichment::ui.article_products.empty_heading'))
            ->emptyStateDescription(__('enrichment::ui.article_products.empty_description'));
    }

    /** @return list<string> */
    private static function reasons(EnrichmentContentProduct $record): array
    {
        $reasons = $record->reasons;
        $lines = [];

        if ($reasons['mentioned'] ?? false) {
            $lines[] = __('enrichment::ui.article_products.reasons.mentioned');
        }
        if (isset($reasons['category'])) {
            $lines[] = __('enrichment::ui.article_products.reasons.category', ['category' => $reasons['category']]);
        }
        if (($reasons['shared_words'] ?? 0) > 0) {
            $lines[] = __('enrichment::ui.article_products.reasons.shared_words', ['count' => $reasons['shared_words']]);
        }
        if (($reasons['superlatives'] ?? 0) > 0) {
            $lines[] = __('enrichment::ui.article_products.reasons.superlatives', ['count' => $reasons['superlatives']]);
        }

        return $lines;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEnrichmentContentProducts::route('/'),
        ];
    }
}
