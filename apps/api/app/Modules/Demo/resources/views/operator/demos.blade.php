<x-filament-panels::page>
    @php($t = fn (string $key, array $with = []) => __('demo::operator.'.$key, $with))
    @php($small = 'font-size:12px;opacity:.7')
    @php($cell = 'padding:8px;border-bottom:1px solid #f1f1f4;text-align:start;vertical-align:top;font-size:13px')
    @php($counts = $this->counts())
    @php($demos = $this->demos())
    @php($cap = (float) \App\Core\Facades\Settings::get('demo.budget_usd'))

    <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">
        <x-filament::link href="{{ route('demo.form') }}" target="_blank" icon="heroicon-m-arrow-top-right-on-square">{{ $t('page') }}</x-filament::link>
        <span style="{{ $small }}" data-cost-total>{{ $t('cost_total', ['usd' => '$'.number_format((float) $demos->sum('spent'), 3), 'count' => $demos->count()]) }}</span>
        <span style="flex:1"></span>
        @foreach (['' => 'all', 'pending' => 'pending', 'scanning' => 'scanning', 'ready' => 'ready', 'failed' => 'failed', 'expired' => 'expired'] as $value => $key)
            <x-filament::button size="xs" :color="$status === $value ? 'primary' : 'gray'" wire:click="$set('status', '{{ $value }}')">
                {{ $t('statuses.'.$key) }} @if ($value !== '')({{ $counts[$value] ?? 0 }})@endif
            </x-filament::button>
        @endforeach
    </div>

    <x-filament::section>
        @if ($demos->isEmpty())
            <p style="{{ $small }}">{{ $t('none') }}</p>
        @else
            <div style="overflow-x:auto">
                <table style="width:100%;border-collapse:collapse">
                    <thead><tr>
                        @foreach (['when', 'site', 'who', 'said', 'status', 'read', 'cost', 'until', ''] as $col)
                            <th style="{{ $cell }};{{ $small }}">{{ $col === '' ? '' : $t('cols.'.$col) }}</th>
                        @endforeach
                    </tr></thead>
                    <tbody>
                        @foreach ($demos as $demo)
                            <tr data-demo="{{ $demo->id }}">
                                <td style="{{ $cell }};white-space:nowrap" dir="ltr">{{ $demo->created_at?->timezone('Asia/Jerusalem')->format('d/m H:i') }}</td>
                                <td style="{{ $cell }}">
                                    <div style="font-weight:600">{{ $demo->title ?? $demo->host }}</div>
                                    <a href="{{ $demo->url }}" target="_blank" rel="noopener" style="{{ $small }}" dir="ltr">{{ $demo->host }}</a>
                                    @if ($demo->platform)<span style="{{ $small }}"> · {{ $demo->platform }}</span>@endif
                                </td>
                                <td style="{{ $cell }}" dir="ltr">{{ $demo->email }}@if ($demo->consent)<div style="{{ $small }}" dir="auto">{{ $t('consent') }}</div>@endif</td>
                                <td style="{{ $cell }}">{{ __('demo::page.start.kinds.'.($demo->choices['kind'] ?? 'store').'.title') }}<div style="{{ $small }}">{{ __('demo::page.start.matters_options.'.($demo->choices['matters'] ?? 'both').'.title') }}</div></td>
                                <td style="{{ $cell }}">
                                    <x-filament::badge :color="['ready' => 'success', 'scanning' => 'info', 'pending' => 'gray', 'failed' => 'danger', 'expired' => 'gray'][$demo->status] ?? 'gray'">{{ $t('statuses.'.$demo->status) }}</x-filament::badge>
                                    @if ($demo->error)<div style="{{ $small }}">{{ $demo->error }}</div>@endif
                                </td>
                                <td style="{{ $cell }};white-space:nowrap">
                                    {{ $t('read_counts', ['products' => number_format($demo->products), 'pages' => number_format($demo->pages)]) }}
                                    @if ($demo->store_products)<div style="{{ $small }}">{{ $t('of_store', ['total' => number_format($demo->store_products)]) }}</div>@endif
                                    @if ($demo->status === 'ready')
                                        {{-- whether its pictures were looked at: what a search by how a product looks needs --}}
                                        <div style="{{ $small }}" data-pictures="{{ $demo->pictures }}">{{ $demo->pictures > 0 ? $t('pictures_read', ['count' => number_format($demo->pictures)]) : $t('pictures_none') }}</div>
                                    @endif
                                </td>
                                <td style="{{ $cell }};white-space:nowrap" data-cost="{{ $demo->spent }}">
                                    @if ($demo->spent === null)
                                        —
                                    @else
                                        <div style="font-weight:600" dir="ltr">${{ number_format((float) $demo->spent, 3) }}</div>
                                        @if ($demo->spent_parts !== null)
                                            <div style="{{ $small }}">{{ $t('cost_parts', ['build' => '$'.number_format($demo->spent_parts['build'], 3), 'visitors' => '$'.number_format($demo->spent_parts['visitors'], 3)]) }}</div>
                                        @endif
                                        @if ($cap > 0)
                                            <div style="{{ $small }}">{{ $t('cost_of_cap', ['percent' => (int) round(100 * $demo->spent / $cap), 'cap' => '$'.rtrim(rtrim(number_format($cap, 2), '0'), '.')]) }}</div>
                                        @endif
                                    @endif
                                </td>
                                <td style="{{ $cell }};white-space:nowrap" dir="ltr">{{ $demo->expires_at?->timezone('Asia/Jerusalem')->format('d/m H:i') ?? '—' }}</td>
                                <td style="{{ $cell }};white-space:nowrap">
                                    @if (in_array($demo->status, ['scanning', 'ready'], true))
                                        <x-filament::link href="{{ route('demo.show', $demo->token) }}" target="_blank" size="sm">{{ $t('open') }}</x-filament::link>
                                        @if ($demo->status === 'ready')
                                            · <x-filament::link tag="button" size="sm" wire:click="extend('{{ $demo->id }}')">{{ $t('extend') }}</x-filament::link>
                                        @endif
                                        · <x-filament::link tag="button" size="sm" wire:click="reshoot('{{ $demo->id }}')">{{ $t('reshoot') }}</x-filament::link>
                                        · <x-filament::link tag="button" size="sm" color="danger" wire:click="endNow('{{ $demo->id }}')" wire:confirm="{{ $t('end_confirm') }}">{{ $t('end') }}</x-filament::link>
                                    @endif
                                    @if (in_array($demo->status, ['scanning', 'ready', 'failed', 'expired'], true))
                                        @if (in_array($demo->status, ['scanning', 'ready'], true)) · @endif
                                        <x-filament::link tag="button" size="sm" color="warning" wire:click="rescan('{{ $demo->id }}')" wire:confirm="{{ $t('rescan_confirm') }}">{{ $t('rescan') }}</x-filament::link>
                                    @endif
                                    · <x-filament::link tag="button" size="sm" color="danger" wire:click="remove('{{ $demo->id }}')" wire:confirm="{{ $t('delete_confirm') }}">{{ $t('delete') }}</x-filament::link>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
