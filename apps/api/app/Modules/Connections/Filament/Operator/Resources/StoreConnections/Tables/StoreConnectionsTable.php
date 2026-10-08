<?php

namespace App\Modules\Connections\Filament\Operator\Resources\StoreConnections\Tables;

use App\Modules\Connections\Enums\ConnectionStatus;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Connections\Support\ConnectionTestNotifier;
use App\Modules\Connections\Support\FollowShopDomain;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class StoreConnectionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('shop'))
            ->columns([
                TextColumn::make('shop.name')
                    ->label(__('connections::connections.fields.shop'))
                    ->description(fn (StoreConnection $record): string => FollowShopDomain::mismatch($record) === null
                        ? $record->site_url
                        : $record->site_url.' · '.__('connections::connections.mismatch', ['domain' => FollowShopDomain::mismatch($record)]))
                    ->searchable(['site_url']),
                TextColumn::make('status')
                    ->label(__('connections::connections.fields.status'))
                    ->badge()
                    ->color(fn (ConnectionStatus $state): string => $state->color())
                    ->formatStateUsing(fn (ConnectionStatus $state): string => $state->label()),
                TextColumn::make('products')
                    ->label(__('connections::connections.fields.published_products'))
                    ->state(fn (StoreConnection $record): mixed => $record->info('counts.products.publish'))
                    ->numeric()
                    ->placeholder('-'),
                TextColumn::make('plugin_version')
                    ->label(__('connections::connections.fields.plugin_version'))
                    ->state(fn (StoreConnection $record): mixed => $record->info('plugin.version'))
                    ->placeholder('-'),
                TextColumn::make('last_checked_at')
                    ->label(__('connections::connections.fields.last_checked_at'))
                    ->since()
                    ->placeholder(__('connections::connections.fields.never')),
            ])
            ->recordActions([
                Action::make('follow_domain')
                    ->label(fn (StoreConnection $record): string => __('connections::connections.actions.follow_domain', ['domain' => (string) FollowShopDomain::mismatch($record)]))
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('warning')
                    ->visible(fn (StoreConnection $record): bool => FollowShopDomain::mismatch($record) !== null)
                    ->requiresConfirmation()
                    ->modalDescription(fn (StoreConnection $record): string => __('connections::connections.follow_domain_help', ['from' => $record->site_url, 'domain' => (string) FollowShopDomain::mismatch($record)]))
                    ->action(function (StoreConnection $record): void {
                        FollowShopDomain::moveToShopDomain($record);
                        ConnectionTestNotifier::test($record->refresh());
                    }),
                Action::make('test')
                    ->label(__('connections::connections.actions.test'))
                    ->icon(Heroicon::OutlinedSignal)
                    ->action(fn (StoreConnection $record) => ConnectionTestNotifier::test($record)),
                EditAction::make(),
            ])
            ->emptyStateHeading(__('connections::connections.empty.heading'))
            ->emptyStateDescription(__('connections::connections.empty.description'));
    }
}
