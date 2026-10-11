<x-filament-panels::page>
    @php($r = $this->report())
    @php($small = 'font-size:12px;opacity:.7')
    @php($input = 'padding:6px 10px;border:1px solid #d4d4d8;border-radius:8px;font:inherit;min-width:0')
    @php($cell = 'padding:6px 8px;border-bottom:1px solid #f1f1f4;text-align:start;vertical-align:top')
    @php($tile = 'padding:14px 16px;border:1px solid #e4e4e7;border-radius:12px;background:#fff;display:grid;gap:2px')

    <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center">
        @if ($this->picksShop())
            <select wire:model.live="shop" style="{{ $input }};min-width:220px">
                @foreach ($this->shops() as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        @endif
        <select wire:model.live="days" style="{{ $input }}">
            @foreach ([7, 30, 90] as $d)
                <option value="{{ $d }}">{{ __('search::ui.days', ['days' => $d]) }}</option>
            @endforeach
        </select>
    </div>

    @if ($r === null)
        <x-filament::section>{{ __('search::ui.no_shop') }}</x-filament::section>
    @else
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px">
            @foreach ([
                'searches' => number_format($r['totals']['searches']),
                'empty' => $r['totals']['empty'].'%',
                'clicks' => $r['totals']['clicks'].'%',
                'photos' => number_format($r['totals']['photos']),
                'questions' => number_format($r['totals']['questions']),
                'answered' => $r['totals']['answered'].'%',
            ] as $key => $value)
                <div style="{{ $tile }}" data-total="{{ $key }}">
                    <span style="{{ $small }}">{{ __('search::ui.analytics.totals.'.$key) }}</span>
                    <strong style="font-size:24px;line-height:1.2">{{ $value }}</strong>
                </div>
            @endforeach
        </div>

        <x-filament::section :heading="__('search::ui.analytics.daily')" :description="__('search::ui.analytics.daily_about')">
            {{-- One bar a day: searches dark, photos light on top; a click lists that day's searches. --}}
            <div style="display:flex;align-items:flex-end;gap:3px;height:160px;padding-top:8px;overflow-x:auto" role="img" aria-label="{{ __('search::ui.analytics.daily') }}">
                @foreach ($r['daily'] as $d)
                    @php($h = (int) round(140 * ($d['searches'] + $d['photos']) / $r['max']))
                    <button type="button" wire:click="pick('{{ $d['day'] }}')" title="{{ \Illuminate\Support\Carbon::parse($d['day'])->format('d/m') }}: {{ $d['searches'] }} · {{ __('search::ui.analytics.totals.photos') }} {{ $d['photos'] }}"
                        style="flex:1 0 10px;min-width:10px;max-width:28px;height:{{ max(2, $h) }}px;border:0;padding:0;cursor:pointer;border-radius:4px 4px 0 0;display:flex;flex-direction:column;justify-content:flex-end;overflow:hidden;background:transparent;outline:{{ $r['day'] === $d['day'] ? '2px solid #111' : 'none' }}">
                        @if ($d['photos'] > 0)
                            <span style="display:block;height:{{ (int) round(100 * $d['photos'] / max(1, $d['searches'] + $d['photos'])) }}%;background:#a5b4fc"></span>
                        @endif
                        <span style="display:block;flex:1;background:#4f46e5"></span>
                    </button>
                @endforeach
            </div>
            <div style="display:flex;justify-content:space-between;{{ $small }};margin-top:4px">
                <span>{{ \Illuminate\Support\Carbon::parse($r['daily'][0]['day'])->format('d/m') }}</span>
                <span>{{ \Illuminate\Support\Carbon::parse(end($r['daily'])['day'])->format('d/m') }}</span>
            </div>

            <div style="overflow-x:auto;margin-top:14px">
                <table style="width:100%;border-collapse:collapse;font-size:13px">
                    <thead>
                        <tr>
                            @foreach (['day', 'searches', 'queries', 'empty', 'clicks', 'photos', 'questions'] as $col)
                                <th style="{{ $cell }};{{ $small }}">{{ __('search::ui.analytics.cols.'.$col) }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach (array_reverse($r['daily']) as $d)
                            @continue($d['searches'] + $d['photos'] + $d['questions'] === 0)
                            <tr wire:click="pick('{{ $d['day'] }}')" style="cursor:pointer;{{ $r['day'] === $d['day'] ? 'background:rgba(79,70,229,.08)' : '' }}" data-day="{{ $d['day'] }}">
                                <td style="{{ $cell }}">{{ \Illuminate\Support\Carbon::parse($d['day'])->translatedFormat('D d/m') }}</td>
                                <td style="{{ $cell }}">{{ $d['searches'] }}</td>
                                <td style="{{ $cell }}">{{ $d['queries'] }}</td>
                                <td style="{{ $cell }}">{{ $d['empty'] }}</td>
                                <td style="{{ $cell }}">{{ $d['clicks'] }}</td>
                                <td style="{{ $cell }}">{{ $d['photos'] }}</td>
                                <td style="{{ $cell }}">{{ $d['questions'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        <x-filament::section :heading="$r['day'] ? __('search::ui.analytics.on_day', ['day' => \Illuminate\Support\Carbon::parse($r['day'])->translatedFormat('l d/m')]) : __('search::ui.analytics.in_period')">
            @if ($r['day'])
                <button type="button" wire:click="pick('{{ $r['day'] }}')" style="{{ $small }};border:0;background:none;cursor:pointer;padding:0;margin-bottom:8px">← {{ __('search::ui.analytics.all_days') }}</button>
            @endif
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:20px">
                <div>
                    <div style="font-weight:600;margin-bottom:6px">{{ __('search::ui.analytics.searched') }}</div>
                    @if ($r['terms']->isEmpty())
                        <div style="{{ $small }}">{{ __('search::ui.analytics.none') }}</div>
                    @else
                        <table style="width:100%;border-collapse:collapse;font-size:13px">
                            <thead><tr>
                                @foreach (['query', 'searches', 'results', 'clicks'] as $col)
                                    <th style="{{ $cell }};{{ $small }}">{{ __('search::ui.analytics.cols.'.$col) }}</th>
                                @endforeach
                            </tr></thead>
                            <tbody>
                                @foreach ($r['terms'] as $t)
                                    <tr data-term>
                                        <td style="{{ $cell }}">{{ $t->query }} @if ((int) $t->empty > 0)<span style="{{ $small }};color:#b45309">· {{ __('search::ui.analytics.found_nothing') }}</span>@endif</td>
                                        <td style="{{ $cell }}">{{ (int) $t->searches }}</td>
                                        <td style="{{ $cell }}">{{ (int) $t->results }}</td>
                                        <td style="{{ $cell }}">{{ (int) $t->clicks }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
                <div>
                    <div style="font-weight:600;margin-bottom:6px">{{ __('search::ui.analytics.clicked') }}</div>
                    @if ($r['clicked']->isEmpty())
                        <div style="{{ $small }}">{{ __('search::ui.analytics.none') }}</div>
                    @else
                        <table style="width:100%;border-collapse:collapse;font-size:13px">
                            <tbody>
                                @foreach ($r['clicked'] as $c)
                                    <tr>
                                        <td style="{{ $cell }}">{{ $c->title ?: $c->item }}</td>
                                        <td style="{{ $cell }};width:60px">{{ (int) $c->clicks }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
