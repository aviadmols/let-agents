<?php

namespace App\Modules\Tenancy\Filament\Operator\Resources\Shops\Schemas;

use App\Core\Localization\Locales;
use App\Modules\Tenancy\Enums\ShopPlatform;
use App\Modules\Tenancy\Enums\ShopStatus;
use App\Modules\Tenancy\Models\Shop;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class ShopForm
{
    /** @var array<string, string> */
    private const CURRENCIES = [
        'ILS' => 'ILS ₪',
        'USD' => 'USD $',
        'EUR' => 'EUR €',
        'GBP' => 'GBP £',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('tenancy::shops.sections.identity'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label(__('tenancy::shops.fields.name'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('slug')
                            ->label(__('tenancy::shops.fields.slug'))
                            ->helperText(__('tenancy::shops.fields.slug_help'))
                            // It becomes the shop's address (gueta.agents.lets.co.il), so it is a web
                            // address label: lowercase letters, digits and dashes, not a reserved name.
                            ->regex('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/')
                            ->notIn(Shop::RESERVED_SLUGS)
                            ->maxLength(63)
                            ->unique(ignoreRecord: true),
                        Select::make('platform')
                            ->label(__('tenancy::shops.fields.platform'))
                            ->options(ShopPlatform::options())
                            ->default(ShopPlatform::WooCommerce->value)
                            ->required(),
                        TextInput::make('domain')
                            ->label(__('tenancy::shops.fields.domain'))
                            ->helperText(__('tenancy::shops.fields.domain_help'))
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        Select::make('status')
                            ->label(__('tenancy::shops.fields.status'))
                            ->options(ShopStatus::options())
                            ->default(ShopStatus::Active->value)
                            ->required(),
                    ]),
                Section::make(__('tenancy::shops.sections.locale'))
                    ->columns(3)
                    ->schema([
                        Select::make('content_locale')
                            ->label(__('tenancy::shops.fields.content_locale'))
                            ->options(self::localeOptions())
                            ->default('he')
                            ->required(),
                        Select::make('currency')
                            ->label(__('tenancy::shops.fields.currency'))
                            ->options(self::CURRENCIES)
                            ->default('ILS')
                            ->required(),
                        Select::make('timezone')
                            ->label(__('tenancy::shops.fields.timezone'))
                            ->options(fn (): array => array_combine(timezone_identifiers_list(), timezone_identifiers_list()))
                            ->searchable()
                            ->default('Asia/Jerusalem')
                            ->required(),
                    ]),
            ]);
    }

    /** @return array<string, string> */
    private static function localeOptions(): array
    {
        $options = [];

        foreach (Locales::supported() as $locale) {
            $options[$locale] = __("tenancy::shops.locales.{$locale}");
        }

        return $options;
    }
}
