<x-filament-panels::page>
    @php($small = 'font-size:12.5px;opacity:.7')
    @php($gaps = $this->gaps())

    @if ($gaps === [])
        <x-filament::section>
            <p style="{{ $small }}">{{ __('enrichment::gaps.none') }}</p>
        </x-filament::section>
    @endif

    @foreach ($gaps as $gap)
        <x-filament::section wire:key="gap-{{ $gap['type'] }}-{{ $gap['attribute'] }}"
            :heading="__('enrichment::gaps.heading', ['type' => $gap['type_label'], 'attribute' => $gap['attribute_label']])"
            :description="__('enrichment::gaps.share', ['have' => $gap['have'], 'of' => $gap['of'], 'missing' => count($gap['missing'])])">
            <x-slot name="afterHeader">
                <x-filament::button size="sm" color="gray" wire:click="reread(@js($gap['type']), @js($gap['attribute']))" wire:confirm="{{ __('enrichment::gaps.reread_confirm', ['count' => min(count($gap['missing']), \App\Modules\Enrichment\Actions\RereadProducts::MAX)]) }}">{{ __('enrichment::gaps.reread') }}</x-filament::button>
            </x-slot>
            <div style="display:grid;gap:2px">
                @foreach ($gap['missing'] as $product)
                    <div style="display:flex;flex-wrap:wrap;justify-content:space-between;gap:8px;padding:8px 0;border-top:1px solid rgba(127,127,127,.15)" data-gap-product="{{ $product['external_id'] }}">
                        <div style="min-width:0">
                            @if ($product['url'])
                                <a href="{{ $product['url'] }}" target="_blank" rel="noopener" style="font-weight:600;font-size:14px">{{ $product['title'] }}</a>
                            @else
                                <span style="font-weight:600;font-size:14px">{{ $product['title'] }}</span>
                            @endif
                            @if ($product['hints'] !== [])
                                <div style="{{ $small }}">{{ __('enrichment::gaps.text_says', ['words' => implode(', ', $product['hints'])]) }}</div>
                            @endif
                        </div>
                        @if ($product['waiting'])
                            <span style="font-size:12.5px;padding:2px 10px;border-radius:999px;background:rgba(245,158,11,.15)">{{ __('enrichment::gaps.waiting', ['value' => $product['waiting']]) }}</span>
                        @endif
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endforeach
</x-filament-panels::page>
