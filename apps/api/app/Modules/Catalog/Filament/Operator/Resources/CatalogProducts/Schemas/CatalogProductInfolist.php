<?php

namespace App\Modules\Catalog\Filament\Operator\Resources\CatalogProducts\Schemas;

use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Catalog\Models\CatalogVariant;
use Filament\Actions\Action;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;

final class CatalogProductInfolist
{
    /**
     * The products the store itself links to this one, by name. A link can point at something the
     * catalog does not hold — a product the store unpublished, or one outside what the plugin
     * sends — and then only the number is left to show.
     *
     * @return list<string>
     */
    private static function relations(CatalogProduct $record): array
    {
        $links = $record->merchantRelations();
        $titles = $links === []
            ? collect()
            : CatalogProduct::query()
                ->where('shop_id', $record->shop_id)
                ->whereIn('external_id', array_column($links, 'target'))
                ->pluck('title', 'external_id');

        return array_map(
            fn (array $link): string => __("catalog::catalog.relations.{$link['type']}").': '
                .($titles[$link['target']] ?? __('catalog::catalog.relations.not_in_catalog'))
                .' #'.$link['target'],
            $links,
        );
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('catalog::catalog.sections.product'))
                    ->columns(4)
                    ->schema([
                        ImageEntry::make('image_url')
                            ->label(__('catalog::catalog.fields.image'))
                            ->imageHeight(120)
                            ->placeholder('-'),
                        TextEntry::make('shop.name')->label(__('catalog::catalog.fields.shop')),
                        TextEntry::make('external_id')
                            ->label(__('catalog::catalog.fields.external_id'))
                            ->fontFamily(FontFamily::Mono),
                        TextEntry::make('sku')->label(__('catalog::catalog.fields.sku'))->placeholder('-'),
                        TextEntry::make('brand')->label(__('catalog::catalog.fields.brand'))->placeholder('-'),
                        TextEntry::make('price')
                            ->label(__('catalog::catalog.fields.price'))
                            ->money(fn (CatalogProduct $record): string => $record->currency ?? 'ILS')
                            ->placeholder('-'),
                        TextEntry::make('in_stock')
                            ->label(__('catalog::catalog.fields.in_stock'))
                            ->formatStateUsing(fn (bool $state): string => $state ? __('catalog::catalog.values.yes') : __('catalog::catalog.values.no')),
                        TextEntry::make('variations_count')->label(__('catalog::catalog.fields.variations')),
                        TextEntry::make('categories')
                            ->label(__('catalog::catalog.fields.categories'))
                            ->state(fn (CatalogProduct $record): array => array_map(fn (array $path): string => implode(' › ', $path), $record->categoryPaths()))
                            ->listWithLineBreaks()
                            ->columnSpan(2),
                        TextEntry::make('source_updated_at')->label(__('catalog::catalog.fields.updated_in_store'))->dateTime()->placeholder('-'),
                        TextEntry::make('synced_at')->label(__('catalog::catalog.fields.synced_at'))->dateTime()->placeholder('-'),
                    ]),
                Section::make(__('catalog::catalog.sections.text'))
                    ->schema([
                        TextEntry::make('short_description')
                            ->label(__('catalog::catalog.fields.short_description'))
                            ->state(fn (CatalogProduct $record): string => $record->shortDescription())
                            ->placeholder('-')
                            ->extraAttributes(['style' => 'white-space: pre-line']),
                        TextEntry::make('description')
                            ->label(__('catalog::catalog.fields.description'))
                            ->state(fn (CatalogProduct $record): string => $record->description())
                            ->placeholder('-')
                            ->extraAttributes(['style' => 'white-space: pre-line']),
                    ]),
                Section::make(__('catalog::catalog.sections.specs'))
                    ->columns(2)
                    ->schema([
                        TextEntry::make('store_attributes')
                            ->label(__('catalog::catalog.fields.store_attributes'))
                            ->state(fn (CatalogProduct $record): array => array_map(
                                fn (array $a): string => $a['name'].': '.implode(', ', $a['values']),
                                $record->storeAttributes(),
                            ))
                            ->listWithLineBreaks()
                            ->placeholder('-'),
                        TextEntry::make('spec_fields')
                            ->label(__('catalog::catalog.fields.spec_fields'))
                            ->state(fn (CatalogProduct $record): array => $record->specFields())
                            ->listWithLineBreaks()
                            ->placeholder('-'),
                        TextEntry::make('relations')
                            ->label(__('catalog::catalog.fields.relations'))
                            ->state(fn (CatalogProduct $record): array => self::relations($record))
                            ->listWithLineBreaks()
                            ->helperText(__('catalog::catalog.fields.relations_help'))
                            ->placeholder('-'),
                    ])
                    ->footerActions([
                        Action::make('edit_widget_page')
                            ->label(__('catalog::catalog.actions.edit_widget_page'))
                            ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                            // The Widget module's page, by its path: a module may not reach into
                            // another module's screens.
                            ->url(fn (CatalogProduct $record): string => url('/operator/widget/page').'?'.http_build_query([
                                'shop' => $record->shop_id,
                                'type' => 'product',
                                'id' => $record->external_id,
                            ])),
                    ]),
                Section::make(__('catalog::catalog.sections.variants'))
                    ->visible(fn (CatalogProduct $record): bool => $record->variants()->exists())
                    ->columns(2)
                    ->schema([
                        TextEntry::make('variant_colors')
                            ->label(__('catalog::catalog.fields.colors'))
                            ->state(fn (CatalogProduct $record): array => self::colors($record))
                            ->helperText(__('catalog::catalog.fields.colors_help'))
                            ->badge()
                            ->placeholder('-'),
                        TextEntry::make('variant_sizes')
                            ->label(__('catalog::catalog.fields.sizes'))
                            ->state(fn (CatalogProduct $record): array => self::sizes($record))
                            ->badge()
                            ->placeholder('-'),
                        TextEntry::make('variant_rows')
                            ->label(__('catalog::catalog.fields.variant_rows'))
                            ->state(fn (CatalogProduct $record): array => $record->variants->map(fn (CatalogVariant $variant): string => self::variantLine($variant))->all())
                            ->helperText(__('catalog::catalog.fields.variant_rows_help'))
                            ->listWithLineBreaks()
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * The colour families the product comes in, in the order its variants first name them; a
     * family none of whose variants is in stock is marked sold out.
     *
     * @return list<string>
     */
    private static function colors(CatalogProduct $record): array
    {
        $families = [];

        foreach ($record->variants as $variant) {
            foreach ($variant->families() as $family) {
                $families[$family->value] = [$family, ($families[$family->value][1] ?? false) || $variant->in_stock];
            }
        }

        return array_values(array_map(
            fn (array $entry): string => $entry[0]->label().($entry[1] ? '' : ' · '.__('catalog::catalog.values.out_of_stock')),
            $families,
        ));
    }

    /** @return list<string> the sizes in the store's order, a sold-out one marked */
    private static function sizes(CatalogProduct $record): array
    {
        $sizes = [];

        foreach ($record->variants->filter(fn (CatalogVariant $v): bool => $v->size_label !== null)->sortBy('size_position') as $variant) {
            $sizes[$variant->size_label] = ($sizes[$variant->size_label] ?? false) || $variant->in_stock;
        }

        return array_map(
            fn (string $size, bool $inStock): string => $size.($inStock ? '' : ' · '.__('catalog::catalog.values.out_of_stock')),
            array_map('strval', array_keys($sizes)),
            array_values($sizes),
        );
    }

    /** "כחול כהה → נייבי (כחול) · פסים · M · במלאי · 3 יח׳" */
    private static function variantLine(CatalogVariant $variant): string
    {
        $color = null;

        if ($variant->color_label !== null) {
            $shades = $variant->shades();
            $color = $shades === []
                ? $variant->color_label.' '.__('catalog::catalog.values.unknown_color')
                : $variant->color_label.' → '.implode(', ', array_map(fn ($s): string => $s->label(), $shades))
                    .' ('.implode(', ', array_map(fn ($f): string => $f->label(), $variant->families())).')';
        }

        $options = (array) $variant->options;

        return implode(' · ', array_filter([
            $color,
            $variant->fabricPattern()?->label(),
            $variant->size_label,
            ...array_map(fn (string $name, string $value): string => $name.': '.$value, array_map('strval', array_keys($options)), array_values($options)),
            __($variant->in_stock ? 'catalog::catalog.values.in_stock' : 'catalog::catalog.values.out_of_stock'),
            $variant->quantity === null ? null : __('catalog::catalog.values.units', ['count' => $variant->quantity]),
        ], fn (?string $part): bool => $part !== null && $part !== ''));
    }
}
