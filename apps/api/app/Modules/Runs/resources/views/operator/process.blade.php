<x-filament-panels::page>
    @php($t = fn (string $key, array $params = []) => __('runs::process.'.$key, $params))
    @php($muted = 'font-size:12px;opacity:.7')
    @php($row = 'display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:10px;border:1px solid rgba(127,127,127,.2)')
    @php($pre = 'white-space:pre-wrap;word-break:break-word;font-size:12px;line-height:1.5;margin:0;padding:10px;border-radius:8px;background:rgba(127,127,127,.08);max-height:520px;overflow:auto')
    @php($dot = fn (bool $ok): string => 'flex:none;width:7px;height:7px;border-radius:50%;background:'.($ok ? '#16a34a' : '#dc2626'))
    @php($when = fn ($at): string => $at ? \Illuminate\Support\Carbon::parse($at)->timezone('Asia/Jerusalem')->format('d/m H:i:s') : '')
    @php($runs = $this->runs())
    @php($opened = $this->openedRun())
    @php($calls = $this->calls())
    @php($call = $this->openedCall())
    @php($dir = fn (?string $text): string => preg_match('/^\s*[\[{]/', (string) $text) ? 'ltr' : 'auto')

    <div>
        <x-filament::link :href="\App\Modules\Runs\Filament\Operator\Pages\PipelineFlow::getUrl()" size="sm">{{ $t('back') }}</x-filament::link>
    </div>

    @if ($this->step !== '')
        <x-filament::section :heading="$t('runs')">
            <div style="display:flex;flex-direction:column;gap:6px">
                @forelse ($runs as $run)
                    <button type="button" wire:click="openRun('{{ $run->id }}')" data-process-run="{{ $run->id }}"
                            @style([$row, 'width:100%;text-align:start;cursor:pointer;background:transparent;color:inherit', 'border-color:#8b5cf6' => $this->run === $run->id])>
                        <span style="{{ $dot($run->status->value !== 'failed') }}"></span>
                        <span style="flex-grow:1;min-width:0">
                            <span style="font-size:12.5px;font-weight:500">{{ $run->summary() ?? $run->status->label() }}</span>
                            <span style="display:block;{{ $muted }}">
                                <span dir="ltr">{{ $when($run->started_at) }}</span>
                                @if ($run->shop) · {{ $run->shop->name }} @endif
                                @if ($run->durationForHumans()) · {{ $run->durationForHumans() }} @endif
                                · {{ $t('calls_count', ['n' => $run->calls_count]) }}
                            </span>
                        </span>
                        @if ((float) $run->cost_usd > 0)
                            <span style="font-size:11px;color:#a21caf;font-weight:600" dir="ltr">${{ number_format((float) $run->cost_usd, 4) }}</span>
                        @endif
                    </button>
                @empty
                    <p style="{{ $muted }}">{{ $t('no_runs') }}</p>
                @endforelse
            </div>
        </x-filament::section>
    @endif

    @if ($opened)
        <x-filament::section :heading="$t('run_heading', ['when' => $when($opened->started_at)])" data-process-opened="{{ $opened->id }}">
            <div style="display:grid;gap:10px">
                <p style="font-size:13px">
                    {{ $opened->agentLabel() }} · {{ $opened->actionLabel() }}
                    @if ($opened->shop) · {{ $opened->shop->name }} @endif
                    · {{ $opened->status->label() }}
                </p>
                @if ($opened->error)
                    <p style="color:#b91c1c;font-size:12px" dir="ltr">{{ $opened->error }}</p>
                @endif
                @if ($input = \App\Modules\Runs\Filament\Operator\Pages\ProcessLog::json($opened->input))
                    <div><div style="{{ $muted }};margin-bottom:4px">{{ $t('run_input') }}</div><pre style="{{ $pre }}" dir="ltr">{{ $input }}</pre></div>
                @endif
                @if ($output = \App\Modules\Runs\Filament\Operator\Pages\ProcessLog::json($opened->output))
                    <div><div style="{{ $muted }};margin-bottom:4px">{{ $t('run_output') }}</div><pre style="{{ $pre }}" dir="ltr">{{ $output }}</pre></div>
                @endif
            </div>
        </x-filament::section>
    @endif

    @if ($opened || $this->step === '')
        <x-filament::section :heading="$opened ? $t('run_calls') : $t('latest_calls')">
            <div style="display:flex;flex-direction:column;gap:6px">
                @forelse ($calls as $c)
                    <div data-process-call="{{ $c->id }}">
                        <button type="button" wire:click="openCall({{ $c->id }})"
                                @style([$row, 'width:100%;text-align:start;cursor:pointer;background:transparent;color:inherit', 'border-color:#8b5cf6' => $this->call === $c->id])>
                            <span style="{{ $dot($c->ok) }}"></span>
                            <span style="flex-grow:1;min-width:0">
                                <span style="font-size:12.5px;font-weight:500">{{ $t('kinds.'.$c->kind) }}</span>
                                <code style="font-size:11.5px;margin-inline-start:6px" dir="ltr">{{ $c->provider }}/{{ $c->model }}</code>
                                <span style="display:block;{{ $muted }}">
                                    <span dir="ltr">{{ $when($c->created_at) }}</span>
                                    @if (! $opened)
                                        · {{ $c->action ? \App\Modules\Runs\Filament\Operator\Pages\PipelineFlow::name($c->action) : $t('outside_run') }}
                                        @if ($c->shop) · {{ $c->shop->name }} @endif
                                    @endif
                                    · {{ $t('tokens', ['in' => number_format($c->input_tokens), 'out' => number_format($c->output_tokens)]) }}
                                    · {{ $t('ms', ['n' => number_format($c->duration_ms)]) }}
                                </span>
                            </span>
                        </button>

                        @if ($call && $call->id === $c->id)
                            <div style="display:grid;gap:10px;padding:10px 4px 6px" data-process-call-open="{{ $call->id }}">
                                @if ($call->error)
                                    <div><div style="{{ $muted }};margin-bottom:4px">{{ $t('error') }}</div><pre style="{{ $pre }};color:#b91c1c" dir="ltr">{{ $call->error }}</pre></div>
                                @endif
                                @if ($call->system)
                                    <div><div style="{{ $muted }};margin-bottom:4px">{{ $t('prompt') }}</div><pre style="{{ $pre }}" dir="auto">{{ $call->system }}</pre></div>
                                @endif
                                <div><div style="{{ $muted }};margin-bottom:4px">{{ $t('sent') }}</div><pre style="{{ $pre }}" dir="{{ $dir($call->sent) }}">{{ \App\Modules\Runs\Filament\Operator\Pages\ProcessLog::readable($call->sent) }}</pre></div>
                                @if ($call->received !== null)
                                    <div><div style="{{ $muted }};margin-bottom:4px">{{ $t('received') }}</div><pre style="{{ $pre }}" dir="ltr">{{ $call->received }}</pre></div>
                                @endif
                            </div>
                        @endif
                    </div>
                @empty
                    <p style="{{ $muted }}">{{ $opened ? $t('no_calls_run') : $t('no_calls') }}</p>
                @endforelse
                @if ($calls->count() >= \App\Modules\Runs\Filament\Operator\Pages\ProcessLog::CALLS)
                    <p style="{{ $muted }}">{{ $t('more', ['n' => \App\Modules\Runs\Filament\Operator\Pages\ProcessLog::CALLS]) }}</p>
                @endif
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
