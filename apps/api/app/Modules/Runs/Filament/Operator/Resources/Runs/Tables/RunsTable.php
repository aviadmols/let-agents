<?php

namespace App\Modules\Runs\Filament\Operator\Resources\Runs\Tables;

use App\Modules\Runs\Enums\RunStatus;
use App\Modules\Runs\Models\Run;
use Filament\Actions\ViewAction;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class RunsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('shop'))
            ->defaultSort('created_at', 'desc')
            // Live: a run started by a button shows up, then finishes, without reloading.
            ->poll('5s')
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('runs::runs.fields.started_at'))
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('runs::runs.fields.status'))
                    ->badge()
                    ->color(fn (RunStatus $state): string => $state->color())
                    ->formatStateUsing(fn (RunStatus $state): string => $state->label()),
                TextColumn::make('agent')
                    ->label(__('runs::runs.fields.agent'))
                    ->formatStateUsing(fn (Run $record): string => $record->agentLabel())
                    ->description(fn (Run $record): string => $record->actionLabel()),
                TextColumn::make('shop.name')
                    ->label(__('runs::runs.fields.shop'))
                    ->placeholder(__('runs::runs.fields.system')),
                TextColumn::make('summary_key')
                    ->label(__('runs::runs.fields.summary'))
                    ->formatStateUsing(fn (Run $record): ?string => $record->summary())
                    ->wrap()
                    ->limit(160)
                    // The result is the column people read; without a width it shrinks to a word per line.
                    ->width('40%'),
                TextColumn::make('duration_ms')
                    ->label(__('runs::runs.fields.duration'))
                    ->formatStateUsing(fn (Run $record): ?string => $record->durationForHumans())
                    ->placeholder('…'),
                TextColumn::make('input_tokens')
                    ->label(__('runs::runs.fields.tokens'))
                    ->formatStateUsing(fn (Run $record): string => number_format($record->input_tokens).' / '.number_format($record->output_tokens))
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('cost_usd')
                    ->label(__('runs::runs.fields.cost'))
                    ->money('USD', decimalPlaces: 4)
                    ->placeholder('-')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('runs::runs.fields.status'))
                    ->options(RunStatus::options()),
                SelectFilter::make('agent')
                    ->label(__('runs::runs.fields.agent'))
                    ->options(fn (): array => Run::query()->distinct()->orderBy('agent')->pluck('agent')
                        ->mapWithKeys(fn (string $agent): array => [$agent => Run::labelFor('agents', $agent)])
                        ->all()),
                SelectFilter::make('shop')
                    ->label(__('runs::runs.fields.shop'))
                    ->relationship('shop', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                // Beside the list, not instead of it: what the run did, what each call sent and got back.
                ViewAction::make()->slideOver()->modalWidth(Width::FourExtraLarge),
            ])
            ->emptyStateHeading(__('runs::runs.empty.heading'))
            ->emptyStateDescription(__('runs::runs.empty.description'));
    }
}
