<x-filament-panels::page>
    @php($areas = $this->areas())
    @php($store = $this->storeAreas())
    @php($current = $areas[$this->area] ?? null)

    <div style="display:grid;grid-template-columns:240px minmax(0,1fr);gap:16px;align-items:start">
        <nav style="display:flex;flex-direction:column;gap:2px;position:sticky;top:16px">
            @foreach ($areas as $key => $area)
                @if ($loop->index === count($store) && count($store) > 0)
                    <div style="margin:10px 0 4px;padding:0 10px;font-size:11px;font-weight:600;color:#9ca3af">{{ __('admin::configuration.tabs.advanced') }}</div>
                @elseif ($loop->first && count($store) > 0)
                    <div style="margin:0 0 4px;padding:0 10px;font-size:11px;font-weight:600;color:#9ca3af">{{ __('admin::configuration.tabs.shop') }}</div>
                @endif

                <button type="button" wire:click="openArea('{{ $key }}')"
                        @style([
                            'text-align:start;padding:7px 10px;border-radius:8px;font-size:13px;line-height:1.3;border:1px solid transparent',
                            'background:#f4f4f5;font-weight:600' => $this->area === $key,
                        ])>
                    {{ $area['title'] }}
                    <span style="float:inline-end;font-size:11px;color:#9ca3af">{{ count($area['keys']) }}</span>
                </button>
            @endforeach
        </nav>

        <div>
            @if ($current)
                <x-filament::section :heading="$current['title']" :description="$current['help']">
                    <form wire:submit="save">
                        {{ $this->form }}

                        <div style="margin-top:14px">
                            <x-filament::button type="submit">{{ __('admin::configuration.save') }}</x-filament::button>
                        </div>
                    </form>
                </x-filament::section>
            @endif

        </div>
    </div>

    @if ($preview = $this->previewUrl())
        <div x-data="{ mobile: false }" style="margin-top:16px">
            <x-filament::section :heading="__('widget::preview.heading')" :description="__('widget::preview.description')">
                <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:12px">
                    <x-filament::button size="xs" color="gray" x-on:click="mobile = false" x-bind:class="mobile ? '' : 'fi-color-primary'">{{ __('widget::preview.desktop') }}</x-filament::button>
                    <x-filament::button size="xs" color="gray" x-on:click="mobile = true" x-bind:class="mobile ? 'fi-color-primary' : ''">{{ __('widget::preview.mobile') }}</x-filament::button>
                    <a href="{{ $preview }}" target="_blank" rel="noopener" style="font-size:12px;text-decoration:underline;margin-inline-start:auto">{{ __('widget::preview.open') }}</a>
                </div>
                <div style="display:flex;justify-content:center;padding:12px;border-radius:16px;background:rgba(127,127,127,.08)">
                    <iframe
                        wire:key="widget-preview-{{ $this->previewVersion }}"
                        src="{{ $preview }}&v={{ $this->previewVersion }}"
                        title="{{ __('widget::preview.heading') }}"
                        loading="lazy"
                        x-bind:style="(mobile ? 'width:390px;max-width:100%;' : 'width:100%;') + 'height:820px;border:0;border-radius:12px;background:#fff'"
                        style="width:100%;height:820px;border:0;border-radius:12px;background:#fff"
                        data-widget-preview
                    ></iframe>
                </div>
            </x-filament::section>
        </div>
    @endif
</x-filament-panels::page>
