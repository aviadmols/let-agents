{{-- Choosing the product a trail or a page trace is about. --}}
@php($chosen = $this->chosen())
<x-filament::section compact>
    <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:center">
        <div style="flex:1 1 260px;position:relative">
            <x-filament::input.wrapper>
                <x-filament::input type="search" wire:model.live.debounce.300ms="search" :placeholder="__('knowledge::map.product.search')" />
            </x-filament::input.wrapper>
            @php($results = $this->results())
            @if ($results !== [])
                <div style="margin-top:6px;border:1px solid rgba(127,127,127,.25);border-radius:8px;overflow:hidden">
                    @foreach ($results as $result)
                        <button type="button" wire:click="choose('{{ $result['id'] }}')" style="display:block;width:100%;text-align:start;padding:6px 10px;font-size:13px;border-bottom:1px solid rgba(127,127,127,.12)">
                            {{ $result['title'] }} <span style="opacity:.5" dir="ltr">#{{ $result['external_id'] }}</span>
                        </button>
                    @endforeach
                </div>
            @elseif (mb_strlen(trim($search)) >= 2)
                <p style="{{ $small }};margin-top:6px">{{ __('knowledge::map.product.none_found') }}</p>
            @endif
        </div>
        <div style="flex:1 1 200px;font-size:13px">
            @if ($chosen)
                {{ __('knowledge::map.product.chosen') }} <strong>{{ $chosen->title }}</strong> <span style="opacity:.5" dir="ltr">#{{ $chosen->external_id }}</span>
            @else
                <span style="opacity:.7">{{ __('knowledge::map.product.choose') }}</span>
            @endif
        </div>
    </div>
</x-filament::section>
