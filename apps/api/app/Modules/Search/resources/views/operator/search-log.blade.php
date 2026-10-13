<x-filament-panels::page>
    @php($t = fn (string $key, array $with = []) => __('search::ui.log.'.$key, $with))
    @php($small = 'font-size:12px;opacity:.7')
    @php($input = 'padding:6px 10px;border:1px solid #d4d4d8;border-radius:8px;font:inherit;min-width:0')
    @php($cell = 'padding:7px 8px;border-bottom:1px solid #f1f1f4;text-align:start;vertical-align:top;font-size:13px')
    @php($searches = $this->searches())
    @php($photos = $this->photos())

    <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center">
        @if ($this->picksShop())
            <select wire:model.live="shop" style="{{ $input }};min-width:220px">
                @foreach ($this->shops() as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        @endif
        <input type="text" wire:model.live.debounce.500ms="ip" placeholder="{{ $t('ip_filter') }}" style="{{ $input }}" dir="ltr">
        @if ($session !== '' || $ip !== '')
            <span style="{{ $small }}">{{ $session !== '' ? $t('one_session', ['session' => $session]) : $t('one_ip', ['ip' => $ip]) }}</span>
            <x-filament::link tag="button" wire:click="clearFilter" size="sm">{{ $t('all') }}</x-filament::link>
        @endif
    </div>

    @if ($photos->isNotEmpty())
        <x-filament::section :heading="$t('photos')">
            <div style="display:flex;flex-wrap:wrap;gap:10px">
                @foreach ($photos as $photo)
                    <div style="width:110px;font-size:12px">
                        @if ($photo->thumb)
                            <img src="data:image/jpeg;base64,{{ $photo->thumb }}" alt="" style="width:110px;border-radius:8px">
                        @endif
                        <div>{{ $photo->object ?? '—' }} · {{ $photo->total }}</div>
                        <div style="{{ $small }}" dir="ltr">{{ $photo->created_at?->timezone('Asia/Jerusalem')->format('d/m H:i') }}</div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif

    <x-filament::section>
        @if ($searches->isEmpty())
            <p style="{{ $small }}">{{ $t('none') }}</p>
        @else
            <div style="overflow-x:auto">
                <table style="width:100%;border-collapse:collapse">
                    <thead><tr>
                        @foreach (['when', 'query', 'results', 'clicked', 'ip', 'device', 'from', 'page', 'session'] as $col)
                            <th style="{{ $cell }};{{ $small }}">{{ $t('cols.'.$col) }}</th>
                        @endforeach
                    </tr></thead>
                    <tbody>
                        @foreach ($searches as $row)
                            <tr data-log="{{ $row->id }}">
                                <td style="{{ $cell }};white-space:nowrap" dir="ltr">{{ $row->created_at?->timezone('Asia/Jerusalem')->format('d/m H:i') }}</td>
                                <td style="{{ $cell }};font-weight:600">{{ $row->query }}</td>
                                <td style="{{ $cell }}">{{ $row->results }}</td>
                                <td style="{{ $cell }}">{{ $row->clicked_title ?? '—' }}</td>
                                <td style="{{ $cell }}" dir="ltr">
                                    @if ($row->ip)
                                        <x-filament::link tag="button" wire:click="$set('ip', @js($row->ip))" size="sm">{{ $row->ip }}</x-filament::link>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td style="{{ $cell }};white-space:nowrap">{{ $row->device ? __('search::ui.visit.'.$row->device) : '—' }}@if ($row->browser) · {{ $row->browser }}@endif @if ($row->os) · {{ $row->os }}@endif</td>
                                <td style="{{ $cell }}" dir="ltr">{{ $row->referrer ?? $t('direct') }}</td>
                                <td style="{{ $cell }};max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" dir="ltr" title="{{ $row->page }}">{{ $row->page ?? '—' }}</td>
                                <td style="{{ $cell }}" dir="ltr">
                                    @if ($row->session_id)
                                        <x-filament::link tag="button" wire:click="$set('session', @js($row->session_id))" size="sm">{{ substr($row->session_id, 0, 8) }}</x-filament::link>
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        <p style="{{ $small }};margin-top:10px">{{ $t('kept', ['days' => (int) \App\Core\Facades\Settings::get('search.visit_keep_days')]) }}</p>
    </x-filament::section>
</x-filament-panels::page>
