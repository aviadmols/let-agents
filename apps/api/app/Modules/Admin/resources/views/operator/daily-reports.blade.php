<x-filament-panels::page>
    @php($small = 'font-size:12.5px;opacity:.7')
    @php($tones = ['good' => 'rgba(27,175,122,.16)', 'warn' => 'rgba(235,170,52,.2)', 'bad' => 'rgba(220,60,60,.15)', '' => 'rgba(127,127,127,.12)'])
    <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center">
        <select wire:model.live="shop" style="padding:6px 10px;border-radius:10px;border:1px solid rgba(127,127,127,.3);background:transparent">
            <option value="">{{ __('admin::daily.all_shops') }}</option>
            @foreach ($this->shops() as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
        </select>
        <select wire:model.live="kind" style="padding:6px 10px;border-radius:10px;border:1px solid rgba(127,127,127,.3);background:transparent">
            <option value="">{{ __('admin::daily.all_kinds') }}</option>
            @foreach ($this->kinds() as $i => $label)<option value="{{ $i }}">{{ $label }}</option>@endforeach
        </select>
    </div>

    @forelse ($this->days() as $date => $entries)
        <x-filament::section :heading="\Carbon\Carbon::parse($date)->translatedFormat('l, j.n.Y')" :description="__('admin::daily.count', ['count' => count($entries)])" collapsible>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:12px">
                @foreach ($entries as $entry)
                    @php($s = $entry['summary'])
                    <article style="display:grid;align-content:start;gap:8px;padding:14px;border-radius:14px;border:1px solid rgba(127,127,127,.2)">
                        <div style="display:flex;justify-content:space-between;gap:8px;align-items:baseline">
                            <strong>{{ $entry['shop'] }}</strong>
                            <span style="{{ $small }}">{{ $entry['kind'] }}</span>
                        </div>
                        <span style="justify-self:start;font-size:12.5px;padding:2px 10px;border-radius:999px;background:{{ $tones[$s->tone] ?? $tones[''] }}">{{ $s->headline }}</span>
                        @if ($s->summary)<p dir="auto" style="margin:0;font-size:13.5px;line-height:1.55;white-space:pre-line">{{ $s->summary }}</p>@endif
                        @foreach ($s->lists as $list)
                            <details @if (($list['tone'] ?? '') === 'warn') open @endif>
                                <summary style="cursor:pointer;font-size:13px;font-weight:600">{{ $list['label'] }}</summary>
                                <ul style="margin:4px 0 0;padding-inline-start:18px;display:grid;gap:3px;font-size:13px">@foreach ($list['items'] as $item)<li dir="auto">{{ $item }}</li>@endforeach</ul>
                            </details>
                        @endforeach
                        @if ($s->url)<a href="{{ $s->url }}" style="font-size:13px;text-decoration:underline">{{ __('admin::daily.open') }}</a>@endif
                    </article>
                @endforeach
            </div>
        </x-filament::section>
    @empty
        <x-filament::section><p style="margin:0;opacity:.75">{{ __('admin::daily.none') }}</p></x-filament::section>
    @endforelse
</x-filament-panels::page>
