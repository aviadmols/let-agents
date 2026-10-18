<x-filament-panels::page>
    @php($t = fn (string $key, array $params = []) => __('admin::timeline.'.$key, $params))
    @php($muted = 'font-size:12px;opacity:.7')
    @php($tz = 'Asia/Jerusalem')
    @php($all = $this->all())
    @php($counts = \App\Modules\Admin\Support\Timeline::counts($all))
    @php($cost = \App\Modules\Admin\Support\Timeline::cost($all))
    @php($dots = ['scan' => '#16a34a', 'catalog' => '#3b5bdb', 'knowledge' => '#7c3aed', 'user' => '#d97706', 'insight' => '#64646c', 'lead' => '#db2777', 'system' => '#9ca3af'])

    <x-filament::section>
        <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center">
            <select wire:model.live="shop" style="font:inherit;padding:6px 10px;border-radius:10px;border:1px solid rgba(127,127,127,.3);background:transparent;color:inherit">
                @foreach ($this->shops() as $s)
                    <option value="{{ $s['slug'] }}" @selected($this->current()?->id === $s['id'])>{{ $s['name'] }}</option>
                @endforeach
            </select>
            @foreach ([7, 30, 90] as $d)
                <x-filament::button size="xs" :color="$this->days === $d ? 'primary' : 'gray'" wire:click="period({{ $d }})">{{ $t('days', ['n' => $d]) }}</x-filament::button>
            @endforeach
            <span style="flex:1"></span>
            <span data-timeline-cost style="font-size:13px"><strong>{{ $t('cost', ['usd' => number_format($cost, 2)]) }}</strong> <span style="{{ $muted }}">· {{ $t('cost_help') }}</span></span>
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:12px">
            <x-filament::button size="xs" :color="$this->kind === '' ? 'primary' : 'gray'" wire:click="pick('')">{{ $t('all') }} ({{ count($all) }})</x-filament::button>
            @foreach (\App\Modules\Admin\Contracts\TimelineEntry::KINDS as $k)
                @if ($counts[$k] > 0)
                    <x-filament::button size="xs" :color="$this->kind === $k ? 'primary' : 'gray'" wire:click="pick('{{ $k }}')">{{ $t('kinds.'.$k) }} ({{ $counts[$k] }})</x-filament::button>
                @endif
            @endforeach
        </div>
    </x-filament::section>

    @forelse ($this->days() as $day => $entries)
        @php($date = \Illuminate\Support\Carbon::parse($day, $tz))
        <div style="margin-top:14px">
            <div style="font-size:12px;letter-spacing:.06em;text-transform:uppercase;opacity:.65;margin:0 0 6px 4px">
                {{ $date->isToday() ? $t('today') : ($date->isYesterday() ? $t('yesterday') : '') }} {{ $date->translatedFormat('l d.m') }}
            </div>
            <div style="display:flex;flex-direction:column;gap:6px;border-inline-start:2px solid rgba(127,127,127,.25);padding-inline-start:14px">
                @foreach ($entries as $e)
                    <div x-data="{ open: false }" data-timeline-entry="{{ $e->key }}" data-timeline-kind="{{ $e->kind }}"
                         style="position:relative;padding:8px 12px;border-radius:10px;border:1px solid rgba(127,127,127,.2)">
                        <span style="position:absolute;inset-inline-start:-20px;top:14px;width:9px;height:9px;border-radius:50%;background:{{ $e->tone === 'bad' ? '#dc2626' : ($dots[$e->kind] ?? '#9ca3af') }}"></span>
                        <div style="display:flex;align-items:center;gap:10px;cursor:{{ $e->lines !== [] ? 'pointer' : 'default' }}" @click="open = !open">
                            <span dir="ltr" style="{{ $muted }};min-width:38px;font-variant-numeric:tabular-nums">{{ $e->at->copy()->timezone($tz)->format('H:i') }}</span>
                            <span style="flex:1;min-width:0;font-size:13.5px;{{ $e->tone === 'bad' ? 'color:#dc2626' : '' }}">{{ $e->title }}</span>
                            @if ($e->who)<span style="{{ $muted }}">{{ $e->who }}</span>@endif
                            @if ($e->costUsd !== null && $e->costUsd > 0)
                                <x-filament::badge size="sm" color="warning"><span dir="ltr">${{ number_format($e->costUsd, $e->costUsd < 0.01 ? 4 : 2) }}</span></x-filament::badge>
                            @endif
                            @if ($e->lines !== [])<span style="{{ $muted }}" x-text="open ? '▲' : '▼'"></span>@endif
                        </div>
                        @if ($e->lines !== [] || $e->url)
                            <div x-show="open" x-cloak style="margin-top:8px;padding-top:8px;border-top:1px solid rgba(127,127,127,.2);font-size:13px">
                                @if ($e->lines !== [])
                                    <ul style="margin:0 0 6px;padding-inline-start:18px;display:flex;flex-direction:column;gap:2px">
                                        @foreach ($e->lines as $line)<li>{{ $line }}</li>@endforeach
                                    </ul>
                                @endif
                                @if ($e->url)<a href="{{ $e->url }}" style="text-decoration:underline;text-underline-offset:3px">{{ $t('open') }}</a>@endif
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @empty
        <p style="{{ $muted }};margin-top:14px">{{ $t('none') }}</p>
    @endforelse
</x-filament-panels::page>
