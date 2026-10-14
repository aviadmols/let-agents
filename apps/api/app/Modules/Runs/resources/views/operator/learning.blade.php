<x-filament-panels::page>
    @php($t = fn (string $key, array $params = []) => __('runs::learning.'.$key, $params))
    @php($muted = 'font-size:12px;opacity:.7')
    @php($card = 'padding:12px 14px;border:1px solid rgba(127,127,127,.2);border-radius:14px;display:grid;gap:6px;align-content:start')
    @php($process = \App\Modules\Runs\Filament\Operator\Pages\ProcessLog::class)
    @php($when = fn ($at): string => $at ? \Illuminate\Support\Carbon::parse($at)->timezone('Asia/Jerusalem')->format('d/m/Y H:i') : '')

    <x-filament::section :heading="$t('agents')" :description="$t('agents_help')">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:12px">
            @foreach ($this->agents() as $agent)
                <div style="{{ $card }}" data-learning-agent="{{ $agent['key'] }}">
                    <div style="display:flex;align-items:center;gap:8px">
                        <strong style="font-size:14px;flex:1">{{ $agent['label'] }}</strong>
                        <x-filament::badge size="sm" color="gray">{{ $t('when.'.(in_array($agent['when'], ['weekly', 'on_request', 'manual'], true) ? $agent['when'] : 'daily'), ['time' => $agent['when']]) }}</x-filament::badge>
                    </div>
                    @if ($agent['about'])<p style="{{ $muted }};margin:0">{{ $agent['about'] }}</p>@endif
                    <div style="{{ $muted }}">
                        {{ $t('counts', ['runs' => number_format($agent['runs']), 'calls' => number_format($agent['calls'])]) }}
                        @if ($agent['failed'] > 0)<span style="color:#dc2626"> · {{ $t('failed', ['n' => $agent['failed']]) }}</span>@endif
                    </div>
                    @if ($agent['last'])
                        <div style="font-size:12.5px">
                            <span style="{{ $muted }}" dir="ltr">{{ $when($agent['last']->started_at) }}</span>
                            · {{ $agent['last']->summary() ?? $agent['last']->status->label() }}
                        </div>
                    @else
                        <div style="{{ $muted }}">{{ $t('never') }}</div>
                    @endif
                    <div style="display:flex;gap:8px;flex-wrap:wrap">
                        <x-filament::button size="xs" :color="$this->agent === $agent['key'] ? 'primary' : 'gray'" wire:click="focus('{{ $agent['key'] }}')">{{ $t('only') }}</x-filament::button>
                        <x-filament::button size="xs" color="gray" tag="a" :href="$process::getUrl(['step' => $agent['action']])">{{ $t('all_runs') }}</x-filament::button>
                    </div>
                </div>
            @endforeach
        </div>
    </x-filament::section>

    <x-filament::section :heading="$t('findings')" :description="$t('findings_help')">
        <div style="display:flex;flex-direction:column;gap:6px">
            @forelse ($this->findings() as $run)
                <a href="{{ $process::getUrl(['step' => $run->action, 'run' => $run->id]) }}" data-learning-run="{{ $run->id }}"
                   style="display:flex;align-items:flex-start;gap:8px;padding:8px 10px;border-radius:10px;border:1px solid rgba(127,127,127,.2);color:inherit;text-decoration:none">
                    <span style="flex:none;margin-top:6px;width:7px;height:7px;border-radius:50%;background:{{ $run->status->value === 'failed' ? '#dc2626' : '#16a34a' }}"></span>
                    <span style="flex:1;min-width:0">
                        <span style="font-size:13px;font-weight:600">{{ $run->agentLabel() }}</span>
                        <span style="display:block;font-size:13px">{{ $run->summary() ?? $run->status->label() }}</span>
                        <span style="display:block;{{ $muted }}">
                            <span dir="ltr">{{ $when($run->started_at) }}</span>
                            @if ($run->shop) · {{ $run->shop->name }} @endif
                            · {{ $t('calls', ['n' => $run->calls_count]) }}
                        </span>
                    </span>
                </a>
            @empty
                <p style="{{ $muted }}">{{ $t('none') }}</p>
            @endforelse
        </div>
    </x-filament::section>
</x-filament-panels::page>
