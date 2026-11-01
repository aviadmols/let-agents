<x-filament-panels::page>
    @php($muted = 'font-size:11.5px;color:#9ca3af')
    @php($rules = $this->inForce())

    <x-filament::section :heading="__('enrichment::scan.in_force', ['version' => $rules['version'] ?? 1])">
        <div style="display:flex;flex-wrap:wrap;gap:5px">
            @foreach (($rules['takeaway_markers'] ?? []) as $marker)
                <span style="padding:2px 9px;border-radius:999px;background:rgba(66,133,244,.10);color:#1d4ed8;font-size:11.5px">{{ $marker }}</span>
            @endforeach
            @foreach (($rules['audience_markers'] ?? []) as $marker)
                <span style="padding:2px 9px;border-radius:999px;background:rgba(217,119,6,.10);color:#b45309;font-size:11.5px">{{ $marker }}</span>
            @endforeach
        </div>

        <div style="margin-top:12px">
            <x-filament::button wire:click="runAudit" size="sm" color="gray">{{ __('enrichment::scan.run_now') }}</x-filament::button>
        </div>
    </x-filament::section>

    <x-filament::section :heading="__('enrichment::scan.proposals')">
        @forelse ($this->proposals() as $proposal)
            <div style="padding:11px 13px;border-radius:12px;border:1px solid #ececee;margin-bottom:8px">
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                    <span @style([
                        'padding:2px 9px;border-radius:999px;font-size:11px;font-weight:600',
                        'background:rgba(22,163,74,.10);color:#15803d' => $proposal->status === 'published',
                        'background:rgba(66,133,244,.10);color:#1d4ed8' => $proposal->status === 'approved',
                        'background:#f4f4f5;color:#6b7280' => ! in_array($proposal->status, ['published', 'approved'], true),
                    ])>{{ __('enrichment::scan.statuses.'.$proposal->status) }}</span>
                    <span style="{{ $muted }}">{{ $proposal->created_at?->diffForHumans() }} · {{ __('enrichment::scan.from_version', ['version' => $proposal->from_version]) }}</span>
                    @if ($proposal->decided_at)
                        <span style="{{ $muted }}">· {{ __('enrichment::scan.decided_by', ['who' => optional($proposal->decided_by)]) }} {{ $proposal->decided_at->diffForHumans() }}</span>
                    @endif
                </div>

                @if ($proposal->summary)
                    <div style="margin-top:6px;font-size:13.5px;line-height:1.45">{{ $proposal->summary }}</div>
                @endif

                @if ($proposal->proposed)
                    <div style="margin-top:6px;display:flex;flex-wrap:wrap;gap:5px">
                        @foreach (($proposal->proposed['takeaway_markers'] ?? []) as $marker)
                            <span style="padding:2px 9px;border-radius:999px;background:rgba(66,133,244,.10);color:#1d4ed8;font-size:11.5px">+ {{ $marker }}</span>
                        @endforeach
                        @foreach (($proposal->proposed['audience_markers'] ?? []) as $marker)
                            <span style="padding:2px 9px;border-radius:999px;background:rgba(217,119,6,.10);color:#b45309;font-size:11.5px">+ {{ $marker }}</span>
                        @endforeach
                    </div>
                @endif

                @if ($proposal->review)
                    <div style="margin-top:6px;{{ $muted }}">
                        {{ __('enrichment::scan.reviewer', ['who' => $proposal->review['by'] ?? '']) }}:
                        {{ implode(' · ', (array) ($proposal->review['reasons'] ?? [])) }}
                        @if (isset($proposal->review['effect']))
                            · {{ __('enrichment::scan.effect', ['before' => $proposal->review['effect']['before'] ?? 0, 'after' => $proposal->review['effect']['after'] ?? 0]) }}
                        @endif
                    </div>
                @endif

                <details style="margin-top:7px">
                    <summary style="font-size:12px;cursor:pointer;color:#6b7280">{{ __('enrichment::scan.evidence') }}</summary>
                    <div style="margin-top:6px;display:flex;flex-direction:column;gap:5px">
                        @foreach ($proposal->findings as $finding)
                            @foreach (($finding['missed'] ?? []) as $miss)
                                <div style="font-size:12px;line-height:1.45">
                                    <span style="{{ $muted }}">#{{ $finding['article'] }}</span> {{ $miss['quote'] }}
                                    <span style="{{ $muted }}">— {{ $miss['why'] }}</span>
                                </div>
                            @endforeach
                        @endforeach
                        <div style="{{ $muted }}">{{ __('enrichment::scan.looked_at', ['n' => count($proposal->sampled)]) }}</div>
                    </div>
                </details>

                @if ($proposal->publishable())
                    <div style="margin-top:10px;display:flex;gap:7px">
                        <x-filament::button size="xs" wire:click="publish(@js($proposal->id))">{{ __('enrichment::scan.publish') }}</x-filament::button>
                        <x-filament::button size="xs" color="gray" wire:click="discard(@js($proposal->id))">{{ __('enrichment::scan.discard') }}</x-filament::button>
                    </div>
                @endif
            </div>
        @empty
            <p style="font-size:13px;color:#6b7280">{{ __('enrichment::scan.nothing_yet') }}</p>
        @endforelse
    </x-filament::section>

    @if ($this->versions() !== [])
        <x-filament::section :heading="__('enrichment::scan.history')">
            <div style="display:flex;flex-direction:column;gap:5px">
                @foreach ($this->versions() as $version)
                    <div style="display:flex;align-items:center;gap:8px;font-size:12.5px">
                        <span style="font-weight:600">v{{ $version->version }}</span>
                        <span style="{{ $muted }}">
                            {{ $version->created_at?->diffForHumans() }}
                            @if ($version->author) · {{ $version->author }} @endif
                            · {{ count($version->rules['takeaway_markers'] ?? []) }} + {{ count($version->rules['audience_markers'] ?? []) }}
                        </span>
                        @if ($version->active)
                            <span style="padding:1px 8px;border-radius:999px;background:rgba(22,163,74,.10);color:#15803d;font-size:10.5px">{{ __('enrichment::scan.active') }}</span>
                        @endif
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
