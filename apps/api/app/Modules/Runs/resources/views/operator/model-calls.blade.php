<x-filament-panels::page>
    @php($small = 'font-size:12px;opacity:.7')
    @php($cell = 'padding:8px 10px;border-bottom:1px solid rgba(127,127,127,.15);text-align:start;vertical-align:top')
    @php($num = 'padding:8px 10px;border-bottom:1px solid rgba(127,127,127,.15);text-align:end;font-variant-numeric:tabular-nums;white-space:nowrap')
    @php($input = 'padding:6px 10px;border:1px solid rgba(127,127,127,.35);border-radius:10px;background:transparent;color:inherit;font:inherit')
    @php($money = fn (float $v): string => '$'.number_format($v, $v > 0 && $v < 1 ? 5 : 2))
    @php([$from, $to] = $this->range())
    @php($totals = $this->totals())
    @php($calls = $this->calls())

    <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:end">
        <label style="display:grid;gap:4px">
            <span style="{{ $small }}">{{ __('runs::spend.from') }}</span>
            <input type="date" wire:model.live="from" value="{{ $from }}" style="{{ $input }}">
        </label>
        <label style="display:grid;gap:4px">
            <span style="{{ $small }}">{{ __('runs::spend.to') }}</span>
            <input type="date" wire:model.live="to" value="{{ $to }}" style="{{ $input }}">
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
        <label style="display:grid;gap:4px">
            <span style="{{ $small }}">{{ __('runs::calls.model') }}</span>
            <select wire:model.live="model" style="{{ $input }}" dir="ltr">
                <option value="">{{ __('runs::calls.all_models') }}</option>
                @foreach ($this->models() as $value => $name)
                    <option value="{{ $value }}">{{ $name }}</option>
                @endforeach
            </select>
        </label>
        <label style="display:grid;gap:4px">
            <span style="{{ $small }}">{{ __('runs::calls.agent') }}</span>
            <select wire:model.live="agent" style="{{ $input }}">
                <option value="">{{ __('runs::calls.all_agents') }}</option>
                @foreach ($this->agents() as $value => $name)
                    <option value="{{ $value }}">{{ $name }}</option>
                @endforeach
            </select>
        </label>
        <span style="{{ $small }};padding-bottom:8px" dir="ltr">{{ $from }} – {{ $to }}</span>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px">
        @foreach ([
            'calls' => number_format($totals['calls']),
            'cost' => $money($totals['cost']),
            'input' => number_format($totals['input']),
            'output' => number_format($totals['output']),
            'cache' => number_format($totals['cache']),
        ] as $key => $value)
            <div style="padding:16px;border:1px solid rgba(127,127,127,.2);border-radius:16px" data-calls-tile="{{ $key }}">
                <div style="{{ $small }}">{{ __('runs::spend.tiles.'.$key) }}</div>
                <div style="font-size:24px;font-weight:700;font-variant-numeric:tabular-nums" dir="ltr">{{ $value }}</div>
            </div>
        @endforeach
    </div>

    <x-filament::section>
        @if ($calls->isEmpty())
            <p style="{{ $small }}">{{ __('runs::calls.empty') }}</p>
        @else
            <div style="overflow-x:auto">
                <table style="width:100%;border-collapse:collapse;font-size:13px">
                    <thead><tr>
                        @foreach (['when', 'shop', 'agent', 'model'] as $col)
                            <th style="{{ $cell }};{{ $small }}">{{ __('runs::calls.cols.'.$col) }}</th>
                        @endforeach
                        @foreach (['input', 'output', 'cache', 'cost'] as $col)
                            <th style="{{ $num }};{{ $small }}">{{ __('runs::spend.cols.'.$col) }}</th>
                        @endforeach
                        <th style="{{ $cell }}"></th>
                    </tr></thead>
                    <tbody>
                        @foreach ($calls as $call)
                            <tr data-call="{{ $call->id }}">
                                <td style="{{ $cell }};white-space:nowrap" dir="ltr">{{ \Illuminate\Support\Carbon::parse($call->created_at)->timezone('Asia/Jerusalem')->format('d/m H:i:s') }}</td>
                                <td style="{{ $cell }}">{{ $call->shop }}</td>
                                <td style="{{ $cell }}">{{ $call->agent_label }}<div style="{{ $small }}">{{ $call->action_label }}</div></td>
                                <td style="{{ $cell }}" dir="ltr"><code>{{ $call->provider }}/{{ $call->model }}</code></td>
                                <td style="{{ $num }}">{{ number_format($call->input_tokens) }}</td>
                                <td style="{{ $num }}">{{ number_format($call->output_tokens) }}</td>
                                <td style="{{ $num }}">{{ number_format($call->cache_read_tokens) }}</td>
                                <td style="{{ $num }};font-weight:700" dir="ltr">{{ $money((float) $call->cost_usd) }}</td>
                                <td style="{{ $cell }}">
                                    @if ($call->url)
                                        <x-filament::link :href="$call->url" size="sm">{{ __('runs::calls.open_run') }}</x-filament::link>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div style="margin-top:12px">{{ $calls->links() }}</div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
