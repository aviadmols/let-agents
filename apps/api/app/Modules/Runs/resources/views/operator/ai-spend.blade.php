<x-filament-panels::page>
    @php($small = 'font-size:12px;opacity:.7')
    @php($cell = 'padding:8px 10px;border-bottom:1px solid rgba(127,127,127,.15);text-align:start;vertical-align:top')
    @php($num = 'padding:8px 10px;border-bottom:1px solid rgba(127,127,127,.15);text-align:end;font-variant-numeric:tabular-nums;white-space:nowrap')
    @php($input = 'padding:6px 10px;border:1px solid rgba(127,127,127,.35);border-radius:10px;background:transparent;color:inherit;font:inherit')
    @php($money = fn (float $v): string => '$'.number_format($v, $v > 0 && $v < 1 ? 4 : 2))
    @php([$from, $to] = $this->range())
    @php($totals = $this->totals())

    <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:end">
        <label style="display:grid;gap:4px">
            <span style="{{ $small }}">{{ __('runs::spend.month') }}</span>
            <select wire:model.live="month" wire:change="clearDays" style="{{ $input }}" data-spend-month>
                @foreach ($this->months() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label style="display:grid;gap:4px">
            <span style="{{ $small }}">{{ __('runs::spend.from') }}</span>
            <input type="date" wire:model.live="from" style="{{ $input }}">
        </label>
        <label style="display:grid;gap:4px">
            <span style="{{ $small }}">{{ __('runs::spend.to') }}</span>
            <input type="date" wire:model.live="to" style="{{ $input }}">
        </label>
        <label style="display:grid;gap:4px">
            <span style="{{ $small }}">{{ __('runs::spend.shop') }}</span>
            <select wire:model.live="shop" style="{{ $input }}">
                <option value="">{{ __('runs::spend.all_shops') }}</option>
                @foreach ($this->shops() as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </label>
        <span style="{{ $small }};padding-bottom:8px" dir="ltr">{{ $from }} – {{ $to }}</span>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px">
        @foreach ([
            'cost' => $money($totals['cost']),
            'input' => number_format($totals['input']),
            'output' => number_format($totals['output']),
            'cache' => number_format($totals['cache']),
            'calls' => number_format($totals['calls']),
        ] as $key => $value)
            <div style="padding:16px;border:1px solid rgba(127,127,127,.2);border-radius:16px" data-spend-tile="{{ $key }}">
                <div style="{{ $small }}">{{ __('runs::spend.tiles.'.$key) }}</div>
                <div style="font-size:26px;font-weight:700;font-variant-numeric:tabular-nums" dir="ltr">{{ $value }}</div>
            </div>
        @endforeach
    </div>

    @php($split = $this->split())
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px">
        @foreach (['background' => 'rgb(140,120,230)', 'live' => 'rgb(80,140,255)'] as $side => $colour)
            <div style="padding:16px;border:1px solid rgba(127,127,127,.2);border-inline-start:4px solid {{ $colour }};border-radius:16px" data-spend-side="{{ $side }}">
                <div style="font-weight:600">{{ __('runs::spend.sides.'.$side) }}</div>
                <div style="{{ $small }}">{{ __('runs::spend.sides.'.$side.'_about') }}</div>
                <div style="display:flex;gap:14px;align-items:baseline;margin-top:8px;flex-wrap:wrap">
                    <span style="font-size:26px;font-weight:700;font-variant-numeric:tabular-nums" dir="ltr">{{ $money($split[$side]['cost']) }}</span>
                    <span style="{{ $small }}">{{ $totals['cost'] > 0 ? round($split[$side]['cost'] / $totals['cost'] * 100) : 0 }}% · {{ number_format($split[$side]['calls']) }} {{ __('runs::spend.cols.calls') }} · {{ number_format($split[$side]['tokens']) }} {{ __('runs::spend.cols.tokens') }}</span>
                </div>
            </div>
        @endforeach
    </div>

    <x-filament::section :heading="__('runs::spend.by_shop')" :description="__('runs::spend.by_shop_about')">
        @php($shops = $this->byShop())
        @if ($shops->isEmpty())
            <p style="{{ $small }}">{{ __('runs::spend.empty') }}</p>
        @else
            <div style="overflow-x:auto">
                <table style="width:100%;border-collapse:collapse;font-size:13.5px">
                    <thead><tr>
                        <th style="{{ $cell }};{{ $small }}">{{ __('runs::spend.cols.shop') }}</th>
                        @foreach (['calls', 'input', 'output', 'cache', 'background', 'live', 'cost'] as $col)
                            <th style="{{ $num }};{{ $small }}">{{ __('runs::spend.cols.'.$col) }}</th>
                        @endforeach
                    </tr></thead>
                    <tbody>
                        @foreach ($shops as $row)
                            <tr data-spend-shop="{{ $row->shop_id }}">
                                <td style="{{ $cell }}">
                                    <details>
                                        <summary style="cursor:pointer;font-weight:600">{{ $row->name }}</summary>
                                        <ul style="margin:6px 0 0;padding-inline-start:18px;font-size:12.5px">
                                            @foreach ($row->models as $m)
                                                <li dir="ltr" style="text-align:start"><code>{{ $m->provider }}/{{ $m->model }}</code> · {{ number_format($m->calls) }} × · {{ number_format($m->input + $m->output) }} tokens · <strong>{{ $money((float) $m->cost) }}</strong></li>
                                            @endforeach
                                        </ul>
                                    </details>
                                </td>
                                <td style="{{ $num }}">{{ number_format($row->calls) }}</td>
                                <td style="{{ $num }}">{{ number_format($row->input) }}</td>
                                <td style="{{ $num }}">{{ number_format($row->output) }}</td>
                                <td style="{{ $num }}">{{ number_format($row->cache) }}</td>
                                <td style="{{ $num }}" dir="ltr" data-spend-shop-background>{{ $money($row->background) }}</td>
                                <td style="{{ $num }}" dir="ltr" data-spend-shop-live>{{ $money($row->live) }}</td>
                                <td style="{{ $num }};font-weight:700" dir="ltr">{{ $money((float) $row->cost) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(360px,1fr));gap:16px">
        <x-filament::section :heading="__('runs::spend.by_model')">
            <table style="width:100%;border-collapse:collapse;font-size:13.5px">
                <thead><tr>
                    <th style="{{ $cell }};{{ $small }}">{{ __('runs::spend.cols.model') }}</th>
                    @foreach (['calls', 'input', 'output', 'cost'] as $col)
                        <th style="{{ $num }};{{ $small }}">{{ __('runs::spend.cols.'.$col) }}</th>
                    @endforeach
                </tr></thead>
                <tbody>
                    @forelse ($this->byModel() as $row)
                        <tr data-spend-model="{{ $row->model }}">
                            <td style="{{ $cell }}" dir="ltr"><code>{{ $row->provider }}/{{ $row->model }}</code></td>
                            <td style="{{ $num }}">{{ number_format($row->calls) }}</td>
                            <td style="{{ $num }}">{{ number_format($row->input) }}</td>
                            <td style="{{ $num }}">{{ number_format($row->output) }}</td>
                            <td style="{{ $num }};font-weight:700" dir="ltr">{{ $money((float) $row->cost) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" style="{{ $cell }};{{ $small }}">{{ __('runs::spend.empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-filament::section>

        <x-filament::section :heading="__('runs::spend.by_agent')">
            <table style="width:100%;border-collapse:collapse;font-size:13.5px">
                <thead><tr>
                    <th style="{{ $cell }};{{ $small }}">{{ __('runs::spend.cols.agent') }}</th>
                    @foreach (['calls', 'tokens', 'cost'] as $col)
                        <th style="{{ $num }};{{ $small }}">{{ __('runs::spend.cols.'.$col) }}</th>
                    @endforeach
                </tr></thead>
                <tbody>
                    @php($sideShown = null)
                    @forelse ($this->byAgent() as $row)
                        @if ($sideShown !== $row->live)
                            @php($sideShown = $row->live)
                            <tr><td colspan="4" style="{{ $cell }};font-weight:600;font-size:12.5px;padding-top:12px">{{ __('runs::spend.sides.'.($row->live ? 'live' : 'background')) }}</td></tr>
                        @endif
                        <tr data-spend-agent="{{ $row->agent }}" data-spend-agent-side="{{ $row->live ? 'live' : 'background' }}">
                            <td style="{{ $cell }}">{{ $row->label }}</td>
                            <td style="{{ $num }}">{{ number_format($row->calls) }}</td>
                            <td style="{{ $num }}">{{ number_format($row->tokens) }}</td>
                            <td style="{{ $num }};font-weight:700" dir="ltr">{{ $money((float) $row->cost) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" style="{{ $cell }};{{ $small }}">{{ __('runs::spend.empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-filament::section>
    </div>

    <x-filament::section :heading="__('runs::spend.by_day')" :description="__('runs::spend.by_day_about')">
        @php($days = $this->byDay())
        @php($peak = max(0.000001, (float) $days->max('cost')))
        <div style="display:grid;gap:4px;font-size:12.5px">
            @foreach ($days as $d)
                <div style="display:grid;grid-template-columns:90px 1fr 90px 110px;gap:10px;align-items:center" data-spend-day="{{ $d->day }}">
                    <span dir="ltr" style="font-variant-numeric:tabular-nums">{{ \Illuminate\Support\Carbon::parse($d->day)->format('d/m') }}</span>
                    <span style="height:12px;border-radius:6px;background:rgba(127,127,127,.12);overflow:hidden;display:flex" title="{{ __('runs::spend.sides.background') }} {{ $money(max(0, $d->cost - $d->live)) }} · {{ __('runs::spend.sides.live') }} {{ $money($d->live) }}">
                        <span style="display:block;height:100%;width:{{ round(max(0, $d->cost - $d->live) / $peak * 100, 1) }}%;background:rgb(140,120,230)"></span>
                        <span style="display:block;height:100%;width:{{ round($d->live / $peak * 100, 1) }}%;background:rgb(80,140,255)"></span>
                    </span>
                    <span style="text-align:end;font-variant-numeric:tabular-nums;{{ $small }}">{{ number_format($d->tokens) }}</span>
                    <strong style="text-align:end;font-variant-numeric:tabular-nums" dir="ltr">{{ $money($d->cost) }}</strong>
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-panels::page>
