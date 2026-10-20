<?php

namespace App\Modules\Runs\Filament\Operator\Resources\Runs\Schemas;

use App\Modules\Runs\Enums\RunStatus;
use App\Modules\Runs\Models\Run;
use App\Modules\Runs\Models\RunCall;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;

final class RunInfolist
{
    /** The calls of one run shown beside the list; a run with more (a nightly batch) is read in the call log. */
    private const CALLS = 20;

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
                // Each call to a model in the run: what it was told, what it was sent (for a search answer: the
                // question, the passages the vectors found and the products), what came back, and what it took.
                Section::make(__('runs::runs.sections.calls'))
                    ->description(__('runs::runs.calls_help'))
                    ->visible(fn (Run $record): bool => $record->calls()->exists())
                    ->schema([
                        RepeatableEntry::make('calls')
                            ->hiddenLabel()
                            ->state(fn (Run $record) => $record->calls()->orderBy('id')->limit(self::CALLS)->get())
                            ->schema([
                                TextEntry::make('model')
                                    ->hiddenLabel()
                                    ->weight('bold')
                                    ->fontFamily(FontFamily::Mono)
                                    ->formatStateUsing(fn (RunCall $record): string => $record->provider.' · '.$record->model.' · '.__('runs::process.tokens', ['in' => number_format($record->input_tokens), 'out' => number_format($record->output_tokens)]).' · '.__('runs::process.ms', ['n' => number_format($record->duration_ms)])),
                                TextEntry::make('error')->label(__('runs::process.error'))->visible(fn (RunCall $record): bool => filled($record->error)),
                                self::text('sent', __('runs::runs.fields.sent'), '28rem'),
                                self::text('received', __('runs::process.received'), '18rem'),
                                Section::make(__('runs::process.prompt'))
                                    ->collapsed()
                                    ->compact()
                                    ->schema([self::text('system', __('runs::process.prompt'), '24rem')->hiddenLabel()]),
                            ]),
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

    /** A call's text as it went or came: JSON laid out to read, in a box of its own height that scrolls. */
    private static function text(string $attribute, string $label, string $height): TextEntry
    {
        return TextEntry::make($attribute)
            ->label($label)
            ->formatStateUsing(function (?string $state): ?string {
                $data = $state === null ? null : json_decode($state, true);

                return is_array($data) ? json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $state;
            })
            ->placeholder('-')
            ->fontFamily(FontFamily::Mono)
            ->extraAttributes(['dir' => 'auto', 'style' => 'white-space: pre-wrap; word-break: break-word; max-height: '.$height.'; overflow: auto; font-size: 12px;']);
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
