<x-filament-panels::page>
    @php($d = $this->details())

    <div>
        <select wire:model.live="shop" style="padding:6px 10px;border:1px solid #d4d4d8;border-radius:8px;min-width:220px">
            @foreach ($this->shops() as $id => $name)
                <option value="{{ $id }}">{{ $name }}</option>
            @endforeach
        </select>
    </div>

    @if ($d === null || $d['connection'] === null)
        <x-filament::section>{{ __('widget::ui.preview.no_connection') }}</x-filament::section>
    @else
        <x-filament::section :heading="__('widget::ui.preview.how_heading')">
            <ol style="list-style:decimal;padding-inline-start:20px;display:grid;gap:6px">
                <li>{{ __('widget::ui.preview.step_plugin', ['version' => $d['plugin_version'] ?? '?']) }}</li>
                <li>{{ __('widget::ui.preview.step_mode') }}</li>
                <li>{{ __('widget::ui.preview.step_open') }}</li>
            </ol>
            <p style="margin-top:12px;font-size:13px;opacity:.8">{{ __('widget::ui.preview.any_page') }}</p>
            <code dir="ltr" style="display:inline-block;margin-top:6px;padding:4px 8px;background:rgba(0,0,0,.05);border-radius:6px">?let_agents_preview={{ $d['preview_key'] }}</code>
        </x-filament::section>

        <x-filament::section :heading="__('widget::ui.preview.placement_heading')">
            <table style="width:100%;font-size:14px;border-collapse:collapse">
                @foreach (['product', 'content'] as $type)
                    @php($p = $d['placement'][$type])
                    <tr style="border-top:1px solid #e4e4e7">
                        <td style="padding:8px 6px;width:30%">{{ __("widget::ui.preview.page_types.{$type}") }}</td>
                        <td style="padding:8px 6px">
                            @if ($p['enabled'])
                                <code dir="ltr">{{ $p['selector'] }}</code>
                                · {{ __("widget::settings.{$type}_position.options.{$p['position']}") }}
                            @else
                                {{ __('widget::ui.preview.off') }}
                            @endif
                        </td>
                    </tr>
                @endforeach
                <tr style="border-top:1px solid #e4e4e7">
                    <td style="padding:8px 6px">{{ __('widget::settings.floating_fallback.label') }}</td>
                    <td style="padding:8px 6px">{{ $d['floating'] ? __('widget::ui.yes') : __('widget::ui.no') }}</td>
                </tr>
            </table>
            <div style="margin-top:12px">
                <x-filament::button tag="a" :href="$d['configure_url']" color="gray" size="sm">
                    {{ __('widget::ui.preview.change_placement') }}
                </x-filament::button>
            </div>
        </x-filament::section>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px">
            @foreach (['products', 'articles'] as $list)
                <x-filament::section :heading="__('widget::ui.preview.'.$list)">
                    <ul style="display:grid;gap:8px">
                        @forelse ($d[$list] as $item)
                            <li>
                                @if ($item['url'])
                                    <a href="{{ $item['url'] }}" target="_blank" rel="noopener" style="text-decoration:underline">{{ $item['title'] }}</a>
                                @else
                                    {{ $item['title'] }}
                                @endif
                            </li>
                        @empty
                            <li style="opacity:.7">{{ __('widget::ui.preview.empty_'.$list) }}</li>
                        @endforelse
                    </ul>
                </x-filament::section>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
