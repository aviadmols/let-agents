<x-filament-panels::page>
    @php($spend = $this->spend())
    @php($muted = 'font-size:11px;color:#9ca3af')

    <x-filament::section>
        <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">
            <span style="font-size:12.5px;color:#6b7280">{{ __('runs::pipeline.spend') }}</span>
            <div style="flex-grow:1;min-width:160px;height:8px;border-radius:999px;background:#f1f1f3;overflow:hidden">
                <div style="width:{{ $spend['share'] }}%;height:100%;border-radius:999px;background:linear-gradient(90deg,#4285f4,#8b5cf6 60%,#ec4899)"></div>
            </div>
            <span style="font-size:13px;font-weight:700">
                ${{ number_format($spend['spent'], 2) }}
                <span style="font-weight:400;color:#9ca3af">{{ __('runs::pipeline.of_cap', ['cap' => number_format($spend['cap'], 2)]) }}</span>
            </span>

            <span style="display:inline-flex;gap:4px">
                @foreach (\App\Modules\Runs\Filament\Operator\Pages\PipelineFlow::WINDOWS as $window)
                    <button type="button" wire:click="setDays({{ $window }})"
                            @style([
                                'font-size:12px;padding:3px 10px;border-radius:999px;border:1px solid #e4e4e7;background:#fff',
                                'background:#1f2937;color:#fff;border-color:#1f2937' => $this->days === $window,
                            ])>{{ __('runs::pipeline.days', ['n' => $window]) }}</button>
                @endforeach
            </span>
        </div>
    </x-filament::section>

    <div style="display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:14px;align-items:start">
        <div style="display:flex;flex-direction:column;gap:10px">
            @foreach ($this->stages() as $stage)
                <x-filament::section :heading="__('runs::pipeline.stages.'.$stage['key'].'.title')"
                                     :description="__('runs::pipeline.stages.'.$stage['key'].'.help')">
                    <div style="display:flex;flex-wrap:wrap;gap:8px">
                        @foreach ($stage['steps'] as $i => $step)
                            @if ($i > 0)
                                <div style="align-self:center;color:#d4d4d8;font-size:17px">‹</div>
                            @endif
                            <a href="{{ \App\Modules\Runs\Filament\Operator\Pages\ProcessLog::getUrl(['step' => $step['action']]) }}" data-pipeline-step="{{ $step['action'] }}" @style([
                                'display:block;color:inherit;text-decoration:none;cursor:pointer',
                                'flex:1 1 200px;min-width:0;padding:9px 11px;border-radius:12px;border:1px solid #ececee;background:#fff',
                                'opacity:.65' => $step['runs'] === 0,
                                'border-color:#fca5a5' => $step['failed'] > 0,
                            ])>
                                <div style="display:flex;align-items:center;gap:6px">
                                    <span @style([
                                        'flex:none;width:7px;height:7px;border-radius:50%',
                                        'background:#16a34a' => $step['runs'] > 0 && $step['failed'] === 0,
                                        'background:#dc2626' => $step['failed'] > 0,
                                        'background:#d4d4d8' => $step['runs'] === 0,
                                    ])></span>
                                    <span style="font-size:12.5px;font-weight:600;line-height:1.25">{{ $step['label'] }}</span>
                                </div>

                                <div style="margin-top:4px;display:flex;flex-wrap:wrap;gap:4px">
                                    @if ($step['model'])
                                        <span style="padding:1px 7px;border-radius:999px;background:rgba(168,85,247,.10);color:#7e22ce;font-size:10px;font-weight:600">{{ $step['model'] }}</span>
                                        @if ($step['outside'])
                                            <span style="padding:1px 7px;border-radius:999px;background:#f4f4f5;color:#6b7280;font-size:10px">{{ __('runs::pipeline.outside') }}</span>
                                        @endif
                                    @else
                                        <span style="padding:1px 7px;border-radius:999px;background:rgba(22,163,74,.10);color:#15803d;font-size:10px;font-weight:600">{{ __('runs::pipeline.in_code') }}</span>
                                    @endif
                                    @if ($step['clock'])
                                        <span style="padding:1px 7px;border-radius:999px;background:rgba(66,133,244,.10);color:#1d4ed8;font-size:10px">{{ $step['clock'] }}</span>
                                    @endif
                                </div>

                                <div style="margin-top:6px;{{ $muted }}">
                                    @if ($step['last'])
                                        {{ \Illuminate\Support\Carbon::parse($step['last'])->diffForHumans() }}
                                        @if ($step['took']) · {{ $step['took'] }} @endif
                                        · {{ __('runs::pipeline.times', ['n' => $step['runs']]) }}
                                        @if ($step['cost'] > 0) · ${{ number_format($step['cost'], 4) }} @endif
                                    @else
                                        {{ __('runs::pipeline.never') }}
                                    @endif
                                </div>

                                @if ($step['failed'] > 0)
                                    <div style="margin-top:3px;font-size:11px;color:#dc2626">{{ __('runs::pipeline.failed', ['n' => $step['failed']]) }}</div>
                                @endif
                            </a>
                        @endforeach
                    </div>
                </x-filament::section>
            @endforeach
        </div>

        <x-filament::section :heading="__('runs::pipeline.recent')">
            <div style="display:flex;flex-direction:column;gap:6px">
                @forelse ($this->recent() as $run)
                    <a href="{{ \App\Modules\Runs\Filament\Operator\Pages\ProcessLog::getUrl(['step' => $run['action'], 'run' => $run['id']]) }}" data-pipeline-run="{{ $run['id'] }}" style="display:flex;align-items:center;gap:8px;padding:7px 9px;border-radius:10px;border:1px solid #ececee;color:inherit;text-decoration:none">
                        <span @style([
                            'flex:none;width:7px;height:7px;border-radius:50%',
                            'background:#16a34a' => $run['status'] === 'succeeded',
                            'background:#dc2626' => $run['status'] === 'failed',
                            'background:#f59e0b' => ! in_array($run['status'], ['succeeded', 'failed'], true),
                        ])></span>
                        <div style="flex-grow:1;min-width:0">
                            <div style="font-size:12px;font-weight:500;line-height:1.3">{{ $run['label'] }}</div>
                            <div style="{{ $muted }}">
                                {{ $run['at']?->diffForHumans() }}@if ($run['took']) · {{ $run['took'] }}@endif
                                @if ($run['shop']) · {{ $run['shop'] }} @endif
                                @if ($run['model']) · {{ $run['model'] }} @endif
                            </div>
                        </div>
                        @if ($run['cost'] > 0)
                            <span style="font-size:11px;color:#a21caf;font-weight:600">${{ number_format($run['cost'], 4) }}</span>
                        @endif
                    </a>
                @empty
                    <p style="font-size:12.5px;color:#6b7280">{{ __('runs::pipeline.nothing_yet') }}</p>
                @endforelse
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
