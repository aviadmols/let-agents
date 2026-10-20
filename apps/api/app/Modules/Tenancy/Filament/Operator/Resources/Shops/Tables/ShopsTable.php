<?php

namespace App\Modules\Tenancy\Filament\Operator\Resources\Shops\Tables;

use App\Core\Facades\Settings;
use App\Modules\Tenancy\Actions\EndTrial;
use App\Modules\Tenancy\Actions\StartTrial;
use App\Modules\Tenancy\Enums\ShopPlatform;
use App\Modules\Tenancy\Enums\ShopStatus;
use App\Modules\Tenancy\Models\Shop;
use App\Modules\Tenancy\Support\TrialLimits;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * Every shop on the platform, one row each: its store, its address here, platform, status,
 * whether the store is connected and how many products it publishes. A row opens the shop's own
 * panel, as the shop sees it.
 */
final class ShopsTable
{
    /**
     * The configuration screen belongs to the Admin module. Linking by route name keeps this
     * module free of any import from it.
     */
    private const CONFIGURATION_ROUTE = 'filament.operator.pages.configuration';

    /** The merchant panel belongs to the Admin module, so this links to it by route name too. */
    private const MERCHANT_OVERVIEW_ROUTE = 'filament.merchant.pages.overview';

    public static function configure(Table $table): Table
    {
        return $table
            // The counts come from other modules' tables, read by name in one query: no import,
            // and no query per row.
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->withCount(['apiKeys as active_keys_count' => fn (Builder $keys) => $keys->whereNull('revoked_at')])
                ->addSelect([
                    'connected' => DB::table('store_connections')->selectRaw('count(*)')
                        ->whereColumn('store_connections.shop_id', 'shops.id')->where('status', 'connected'),
                    'plan' => DB::table('shopify_installs')->select('subscription_status')
                        ->whereColumn('shopify_installs.shop_id', 'shops.id')->limit(1),
                    'products_count' => DB::table('catalog_products')->selectRaw('count(*)')
                        ->whereColumn('catalog_products.shop_id', 'shops.id')->whereNull('removed_at'),
                ]))
            ->defaultSort('created_at', 'desc')
            ->recordUrl(fn (Shop $record): ?string => self::merchantUrl($record))
            ->columns([
                TextColumn::make('domain')
                    ->label(__('tenancy::shops.fields.domain'))
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),
                TextColumn::make('slug')
                    ->label(__('tenancy::shops.fields.address'))
                    ->searchable()
                    ->formatStateUsing(fn (string $state): string => self::address($state))
                    ->toggleable(),
                TextColumn::make('name')
                    ->label(__('tenancy::shops.fields.name'))
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('platform')
                    ->label(__('tenancy::shops.fields.platform'))
                    ->badge()
                    ->color(fn (ShopPlatform $state): string => $state === ShopPlatform::Shopify ? 'success' : 'info')
                    ->formatStateUsing(fn (ShopPlatform $state): string => $state->label()),
                TextColumn::make('status')
                    ->label(__('tenancy::shops.fields.status'))
                    ->badge()
                    ->color(fn (ShopStatus $state): string => $state->color())
                    ->formatStateUsing(fn (ShopStatus $state): string => $state->label()),
                IconColumn::make('connected')
                    ->label(__('tenancy::shops.fields.connected'))
                    ->boolean()
                    ->state(fn (Shop $record): bool => (int) $record->getAttribute('connected') > 0),
                // A Shopify store pays through Shopify; its plan is the subscription Shopify reports.
                TextColumn::make('plan')
                    ->label(__('tenancy::shops.fields.plan'))
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'active' => 'success',
                        'pending' => 'warning',
                        null => 'gray',
                        default => 'danger',
                    })
                    ->formatStateUsing(fn (?string $state): string => __('tenancy::shops.plans.'.($state ?? 'none')))
                    ->placeholder(__('tenancy::shops.plans.none')),
                // A shop on trial reads fewer products and pictures (TrialLimits) until the trial is ended.
                TextColumn::make('trial_ends_at')
                    ->label(__('tenancy::shops.fields.trial'))
                    ->badge()
                    ->color(fn (Shop $record): string => $record->trialDaysLeft() > 0 ? 'warning' : 'danger')
                    ->formatStateUsing(fn (Shop $record): string => $record->trialDaysLeft() > 0
                        ? __('tenancy::shops.trial.left', ['days' => $record->trialDaysLeft()])
                        : __('tenancy::shops.trial.ended'))
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('products_count')
                    ->label(__('tenancy::shops.fields.products'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('active_keys_count')
                    ->label(__('tenancy::shops.fields.active_keys'))
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('content_locale')
                    ->label(__('tenancy::shops.fields.content_locale'))
                    ->formatStateUsing(fn (string $state): string => __("tenancy::shops.locales.{$state}"))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label(__('tenancy::shops.fields.created_at'))
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('tenancy::shops.fields.status'))
                    ->options(ShopStatus::options()),
                SelectFilter::make('platform')
                    ->label(__('tenancy::shops.fields.platform'))
                    ->options(ShopPlatform::options()),
            ])
            ->recordActions([
                Action::make('merchant_view')
                    ->label(__('tenancy::shops.actions.merchant_view'))
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->visible(fn (): bool => Route::has(self::MERCHANT_OVERVIEW_ROUTE))
                    ->url(fn (Shop $record): ?string => self::merchantUrl($record)),
                Action::make('configure')
                    ->label(__('tenancy::shops.actions.configure'))
                    ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                    ->visible(fn (): bool => Route::has(self::CONFIGURATION_ROUTE))
                    ->url(fn (Shop $record): string => route(self::CONFIGURATION_ROUTE, ['shop' => $record->id])),
                EditAction::make(),
                ActionGroup::make([
                    Action::make('start_trial')
                        ->label(fn (Shop $record): string => __($record->onTrial() ? 'tenancy::shops.trial.renew' : 'tenancy::shops.trial.start'))
                        ->icon(Heroicon::OutlinedClock)
                        ->modalDescription(fn (): string => __('tenancy::shops.trial.start_body', TrialLimits::words()))
                        ->schema([
                            TextInput::make('days')
                                ->label(__('tenancy::shops.fields.trial_days'))
                                ->numeric()
                                ->minValue(1)
                                ->maxValue(90)
                                ->default(fn (): int => (int) Settings::get('tenancy.trial_days'))
                                ->required(),
                        ])
                        ->action(function (Shop $record, array $data): void {
                            app(StartTrial::class)->handle($record, (int) $data['days']);
                            Notification::make()->success()->title(__('tenancy::shops.trial.started'))->send();
                        }),
                    Action::make('end_trial')
                        ->label(__('tenancy::shops.trial.end'))
                        ->icon(Heroicon::OutlinedCheckBadge)
                        ->color('success')
                        ->visible(fn (Shop $record): bool => $record->onTrial())
                        ->requiresConfirmation()
                        ->modalDescription(__('tenancy::shops.trial.end_body'))
                        ->action(function (Shop $record): void {
                            app(EndTrial::class)->handle($record);
                            Notification::make()->success()->title(__('tenancy::shops.trial.ended_done'))->send();
                        }),
                ])->label(__('tenancy::shops.fields.trial'))->icon(Heroicon::OutlinedClock),
            ]);
    }

    /** The shop's own panel: on its own address when the platform has a shop domain. */
    public static function merchantUrl(Shop $shop): ?string
    {
        return Route::has(self::MERCHANT_OVERVIEW_ROUTE) ? route(self::MERCHANT_OVERVIEW_ROUTE, ['tenant' => $shop->slug]) : null;
    }

    /** "gueta-avigdor.agents.lets.co.il", or the slug alone while every shop shares one address. */
    private static function address(string $slug): string
    {
        $domain = (string) config('upsell.shop_domain');

        return $domain !== '' ? $slug.'.'.$domain : $slug;
    }
}
