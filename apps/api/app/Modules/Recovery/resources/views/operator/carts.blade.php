<x-filament-panels::page>
    @php($small = 'font-size:12px;opacity:.7')
    @php($pill = 'display:inline-flex;padding:2px 10px;border-radius:999px;font-size:12px;font-weight:600')
    @php($tone = ['open' => 'background:rgba(127,127,127,.15)', 'converted' => 'background:#e2ff78;color:#111', 'reported' => 'background:rgba(235,104,52,.18)'])
    @php($on = $this->on())
    @php($operator = $this->operatorView())

    <x-filament::section>
        <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between">
            <span>{{ __('recovery::ui.'.($on ? 'on' : 'off')) }}</span>
            <x-filament::button :color="$on ? 'gray' : 'primary'" wire:click="setOn({{ $on ? 'false' : 'true' }})" data-recovery-switch>
                {{ __('recovery::ui.'.($on ? 'turn_off' : 'turn_on')) }}
            </x-filament::button>
        </div>
    </x-filament::section>

    @php($tiles = $this->tiles())
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px">
        @foreach ($tiles as $key => $n)
            <div style="padding:16px;border:1px solid rgba(127,127,127,.2);border-radius:16px">
                <div style="{{ $small }}">{{ __('recovery::ui.tiles.'.$key) }}</div>
                <div style="font-size:28px;font-weight:700">{{ number_format($n) }}</div>
            </div>
        @endforeach
    </div>

    <x-filament::section>
        <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px">
            @foreach (\App\Modules\Recovery\Filament\Operator\Pages\AbandonedCarts::FILTERS as $key)
                <x-filament::button size="sm" :color="$filter === $key ? 'primary' : 'gray'" wire:click="$set('filter', '{{ $key }}')">{{ __('recovery::ui.filter.'.$key) }}</x-filament::button>
            @endforeach
        </div>

        @php($carts = $this->carts())
        @if ($carts->isEmpty())
            <p style="{{ $small }}">{{ __('recovery::ui.empty') }}</p>
        @else
            @php($names = $this->names($carts))
            <div style="display:grid;gap:10px">
                @foreach ($carts as $cart)
                    @php($report = $cart->report ?? [])
                    <details style="border:1px solid rgba(127,127,127,.2);border-radius:14px;padding:12px 16px" data-cart="{{ $cart->status }}">
                        <summary style="cursor:pointer;display:flex;flex-wrap:wrap;gap:10px;align-items:center">
                            <span style="{{ $pill }};{{ $tone[$cart->status] ?? $tone['open'] }}">{{ __('recovery::ui.status.'.$cart->status) }}</span>
                            <strong dir="ltr">{{ $cart->email }}</strong>
                            <span>{{ number_format((float) $cart->total) }} {{ $cart->currency }}</span>
                            <span style="{{ $small }}">{{ $cart->captured_at->timezone('Asia/Jerusalem')->format('d/m H:i') }}</span>
                        </summary>

                        <div style="display:grid;gap:12px;margin-top:12px;font-size:14px">
                            <ul style="margin:0;padding-inline-start:18px">
                                @foreach ($cart->items as $item)
                                    <li>{{ $names[$item['product_id']] ?? '#'.$item['product_id'] }} × {{ $item['quantity'] }}</li>
                                @endforeach
                            </ul>

                            @if ($cart->status === 'open')
                                <span style="{{ $small }}">{{ __('recovery::ui.report.waiting', ['time' => $this->reportAt($cart)]) }}</span>
                            @elseif ($cart->status === 'reported' && ($report['thin'] ?? false))
                                <span style="{{ $small }}">{{ __('recovery::ui.report.thin') }}</span>
                            @elseif ($cart->status === 'reported')
                                @if ($operator && $cart->report_checked === false)
                                    <span style="{{ $pill }};{{ $tone['reported'] }};justify-self:start">⚠ {{ __('recovery::ui.report.unchecked') }}</span>
                                @endif
                                @foreach (['interest', 'searched', 'path'] + ($cart->report_checked === false && ! $operator ? [] : [3 => 'hesitation']) as $key)
                                    @if (! empty($report[$key]))
                                        <div><div style="{{ $small }}">{{ __('recovery::ui.report.'.$key) }}</div>{{ $report[$key] }}</div>
                                    @endif
                                @endforeach
                                @if (! empty($report['suggestions']) && ($cart->report_checked !== false || $operator))
                                    <div>
                                        <div style="{{ $small }}">{{ __('recovery::ui.report.suggestions') }}</div>
                                        <ul style="margin:4px 0 0;padding-inline-start:18px">
                                            @foreach ($report['suggestions'] as $suggestion)
                                                <li>{{ $suggestion['what'] }}</li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @endif
                            @endif

                            @if ($cart->admin_url)
                                <a href="{{ $cart->admin_url }}" target="_blank" rel="noopener" style="font-weight:600;justify-self:start">{{ __('recovery::ui.order') }} ↗</a>
                            @endif
                        </div>
                    </details>
                @endforeach
            </div>
            <div style="margin-top:12px">{{ $carts->links() }}</div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
