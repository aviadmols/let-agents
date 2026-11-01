<x-filament-panels::page>
    @php($small = 'font-size:12px;opacity:.7')
    @php($cell = 'padding:9px 10px;border-bottom:1px solid #f1f1f3;vertical-align:top')
    @php($counts = $this->counts())
    @php($leads = $this->leads())

    <x-filament::section>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            @foreach (['new', 'handled', 'discarded', 'all'] as $which)
                <x-filament::button
                    size="sm"
                    :color="$this->show === $which ? 'primary' : 'gray'"
                    wire:click="setShow(@js($which))">
                    {{ __('leads::list.tabs.'.$which) }}
                    @if ($which !== 'all' && ($counts[$which] ?? 0) > 0)
                        ({{ $counts[$which] }})
                    @endif
                </x-filament::button>
            @endforeach
        </div>
    </x-filament::section>

    @if ($leads->isEmpty())
        <x-filament::section>
            <p>{{ __('leads::list.none') }}</p>
        </x-filament::section>
    @else
        <x-filament::section>
            <table style="width:100%;border-collapse:collapse;font-size:13px">
                <tr style="{{ $small }}">
                    <td style="{{ $cell }}">{{ __('leads::list.columns.contact') }}</td>
                    <td style="{{ $cell }}">{{ __('leads::list.columns.answers') }}</td>
                    <td style="{{ $cell }}">{{ __('leads::list.columns.page') }}</td>
                    <td style="{{ $cell }}">{{ __('leads::list.columns.quality') }}</td>
                    <td style="{{ $cell }}">{{ __('leads::list.columns.when') }}</td>
                    <td style="{{ $cell }}"></td>
                </tr>
                @foreach ($leads as $lead)
                    <tr>
                        <td style="{{ $cell }};font-variant-numeric:tabular-nums;direction:ltr;text-align:start">
                            {{ $this->contactOf($lead) }}
                            @unless (isset($this->revealed[$lead->id]))
                                <button type="button" wire:click="reveal(@js($lead->id))"
                                        style="margin-inline-start:6px;{{ $small }};text-decoration:underline">{{ __('leads::list.reveal') }}</button>
                            @endunless
                        </td>
                        <td style="{{ $cell }}">
                            @foreach (($lead->answers ?? []) as $key => $value)
                                <div>{{ $value }}</div>
                            @endforeach
                        </td>
                        <td style="{{ $cell }};{{ $small }}">{{ $lead->page_external_id }}</td>
                        <td style="{{ $cell }};font-variant-numeric:tabular-nums">
                            <x-filament::badge :color="$lead->quality >= 75 ? 'success' : ($lead->quality >= 50 ? 'warning' : 'gray')">
                                {{ $lead->quality }}
                            </x-filament::badge>
                        </td>
                        <td style="{{ $cell }};{{ $small }}">{{ $lead->created_at->diffForHumans() }}</td>
                        <td style="{{ $cell }};white-space:nowrap">
                            @if ($lead->status !== 'handled')
                                <x-filament::button size="xs" color="gray" wire:click="mark(@js($lead->id), 'handled')">{{ __('leads::list.mark_handled') }}</x-filament::button>
                            @endif
                            @if ($lead->status !== 'discarded')
                                <x-filament::button size="xs" color="gray" wire:click="mark(@js($lead->id), 'discarded')">{{ __('leads::list.mark_discarded') }}</x-filament::button>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </table>
        </x-filament::section>
    @endif

    <p style="{{ $small }}">{{ __('leads::list.privacy') }}</p>
</x-filament-panels::page>
