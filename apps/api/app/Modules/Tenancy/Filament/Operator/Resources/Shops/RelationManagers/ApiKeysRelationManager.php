<?php

namespace App\Modules\Tenancy\Filament\Operator\Resources\Shops\RelationManagers;

use App\Modules\Tenancy\Actions\IssueApiKey;
use App\Modules\Tenancy\Actions\RevokeApiKey;
use App\Modules\Tenancy\Exceptions\ApiKeyLimitReached;
use App\Modules\Tenancy\Models\Shop;
use App\Modules\Tenancy\Models\ShopApiKey;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class ApiKeysRelationManager extends RelationManager
{
    protected static string $relationship = 'apiKeys';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('tenancy::api_keys.plural');
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->label(__('tenancy::api_keys.fields.name')),
                TextColumn::make('prefix')
                    ->label(__('tenancy::api_keys.fields.prefix'))
                    ->fontFamily('mono')
                    ->formatStateUsing(fn (string $state): string => $state.'_…'),
                TextColumn::make('status')
                    ->label(__('tenancy::api_keys.fields.status'))
                    ->badge()
                    ->state(fn (ShopApiKey $record): string => $record->isRevoked() ? 'revoked' : 'active')
                    ->color(fn (string $state): string => $state === 'active' ? 'success' : 'gray')
                    ->formatStateUsing(fn (string $state): string => __("tenancy::api_keys.statuses.{$state}")),
                TextColumn::make('last_used_at')
                    ->label(__('tenancy::api_keys.fields.last_used_at'))
                    ->since()
                    ->placeholder(__('tenancy::api_keys.fields.never_used')),
                TextColumn::make('created_at')
                    ->label(__('tenancy::api_keys.fields.created_at'))
                    ->since(),
            ])
            ->headerActions([
                Action::make('issue')
                    ->label(__('tenancy::api_keys.actions.issue'))
                    ->icon(Heroicon::OutlinedKey)
                    ->schema([
                        TextInput::make('name')
                            ->label(__('tenancy::api_keys.fields.name'))
                            ->helperText(__('tenancy::api_keys.fields.name_help'))
                            ->required()
                            ->maxLength(100),
                    ])
                    ->action(function (array $data, IssueApiKey $issue): void {
                        /** @var Shop $shop */
                        $shop = $this->getOwnerRecord();

                        try {
                            $issued = $issue->handle($shop, $data['name']);
                        } catch (ApiKeyLimitReached $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        // Shown once, in a window that stays until it is closed, with a button that copies it:
                        // a notification could close (or never show) before the key was copied.
                        $this->replaceMountedAction('showKey', ['key' => $issued->plaintext]);
                    }),
            ])
            ->recordActions([
                // (the issued key's window, opened by "issue": showKeyAction())
                Action::make('revoke')
                    ->label(__('tenancy::api_keys.actions.revoke'))
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(__('tenancy::api_keys.actions.revoke_confirm'))
                    ->visible(fn (ShopApiKey $record): bool => ! $record->isRevoked())
                    ->action(fn (ShopApiKey $record, RevokeApiKey $revoke) => $revoke->handle($record)),
            ]);
    }

    /** The key just issued, the only time it is shown: read-only, with a button that copies it. */
    public function showKeyAction(): Action
    {
        return Action::make('showKey')
            ->modalHeading(__('tenancy::api_keys.issued.title'))
            ->modalDescription(__('tenancy::api_keys.issued.only_now'))
            ->fillForm(fn (array $arguments): array => ['key' => (string) ($arguments['key'] ?? '')])
            ->schema([
                TextInput::make('key')
                    ->label(__('tenancy::api_keys.issued.key'))
                    ->readOnly()
                    ->copyable(copyMessage: __('tenancy::api_keys.issued.copied'))
                    ->extraInputAttributes(['dir' => 'ltr', 'style' => 'font-family: monospace']),
            ])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('tenancy::api_keys.issued.close'));
    }
}
