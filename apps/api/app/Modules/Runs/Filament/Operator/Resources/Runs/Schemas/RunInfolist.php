<?php

namespace App\Modules\Runs\Filament\Operator\Resources\Runs\Schemas;

use App\Modules\Runs\Enums\RunStatus;
use App\Modules\Runs\Models\Run;
use App\Modules\Runs\Support\RunFlow;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;

final class RunInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('runs::runs.sections.overview'))
                    ->columns(3)
                    ->schema([
                        TextEntry::make('status')
                            ->label(__('runs::runs.fields.status'))
                            ->badge()
                            ->color(fn (RunStatus $state): string => $state->color())
                            ->formatStateUsing(fn (RunStatus $state): string => $state->label()),
                        TextEntry::make('agent')
                            ->label(__('runs::runs.fields.agent'))
                            ->formatStateUsing(fn (Run $record): string => $record->agentLabel()),
                        TextEntry::make('action')
                            ->label(__('runs::runs.fields.action'))
                            ->formatStateUsing(fn (Run $record): string => $record->actionLabel()),
                        TextEntry::make('shop.name')
                            ->label(__('runs::runs.fields.shop'))
                            ->placeholder(__('runs::runs.fields.system')),
                        TextEntry::make('trigger')
                            ->label(__('runs::runs.fields.trigger'))
                            ->formatStateUsing(fn (Run $record): string => $record->trigger->label()),
                        TextEntry::make('user.name')
                            ->label(__('runs::runs.fields.user'))
                            ->placeholder('-'),
                        TextEntry::make('started_at')
                            ->label(__('runs::runs.fields.started_at'))
                            ->dateTime(),
                        TextEntry::make('finished_at')
                            ->label(__('runs::runs.fields.finished_at'))
                            ->dateTime()
                            ->placeholder('…'),
                        TextEntry::make('duration_ms')
                            ->label(__('runs::runs.fields.duration'))
                            ->formatStateUsing(fn (Run $record): ?string => $record->durationForHumans())
                            ->placeholder('…'),
                    ]),
                Section::make(__('runs::runs.sections.result'))
                    ->schema([
                        TextEntry::make('summary_key')
                            ->label(__('runs::runs.fields.summary'))
                            ->formatStateUsing(fn (Run $record): ?string => $record->summary()),
                        TextEntry::make('error')
                            ->label(__('runs::runs.fields.error'))
                            ->fontFamily(FontFamily::Mono)
                            ->extraAttributes(['dir' => 'ltr'])
                            ->visible(fn (Run $record): bool => filled($record->error)),
                    ]),
                Section::make(__('runs::runs.sections.usage'))
                    ->columns(4)
                    ->visible(fn (Run $record): bool => filled($record->provider))
                    ->schema([
                        TextEntry::make('provider')->label(__('runs::runs.fields.provider')),
                        TextEntry::make('model')->label(__('runs::runs.fields.model'))->fontFamily(FontFamily::Mono),
                        TextEntry::make('input_tokens')
                            ->label(__('runs::runs.fields.tokens'))
                            ->formatStateUsing(fn (Run $record): string => __('runs::runs.fields.tokens_detail', [
                                'input' => number_format($record->input_tokens),
                                'output' => number_format($record->output_tokens),
                                'cached' => number_format($record->cache_read_tokens),
                            ])),
                        TextEntry::make('cost_usd')->label(__('runs::runs.fields.cost'))->money('USD', decimalPlaces: 4)->placeholder('-'),
                    ]),
                // The run as it went: each step in order, what it does, when, how long, what it cost; each call to a
                // model opens to what it was sent (for a search answer: the question, the passages, the products).
                Section::make(__('runs::flow.heading'))
                    ->description(__('runs::flow.description'))
                    ->schema([
                        ViewEntry::make('flow')
                            ->hiddenLabel()
                            ->view('runs::operator.run-flow')
                            ->state(fn (Run $record): array => RunFlow::of($record)),
                    ]),
                Section::make(__('runs::runs.sections.data'))
                    ->columns(2)
                    ->collapsible()
                    ->schema([
                        self::json('input', __('runs::runs.fields.input')),
                        self::json('output', __('runs::runs.fields.output')),
                    ]),
            ]);
    }

    private static function json(string $attribute, string $label): TextEntry
    {
        return TextEntry::make($attribute)
            ->label($label)
            ->state(fn (Run $record): ?string => $record->{$attribute} === null
                ? null
                : json_encode($record->{$attribute}, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
            ->placeholder('-')
            ->fontFamily(FontFamily::Mono)
            ->extraAttributes(['dir' => 'ltr', 'style' => 'white-space: pre-wrap; word-break: break-word;']);
    }
}
