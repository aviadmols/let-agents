{{-- The last run of this lane and what the month has cost so far. Needs $last, $spend and the page's styles. --}}
<x-filament::section :heading="__('knowledge::map.run.title')" compact>
    <div style="display:flex;flex-wrap:wrap;gap:24px">
        <div style="flex:1 1 280px">
            @if ($last === null)
                <p style="opacity:.7">{{ __('knowledge::map.run.never') }}</p>
            @else
                <div style="display:flex;gap:8px;align-items:center">
                    <x-filament::badge :color="$last['status'] === 'succeeded' ? 'success' : ($last['status'] === 'failed' ? 'danger' : 'warning')">
                        {{ __('knowledge::map.run.status.'.$last['status']) }}
                    </x-filament::badge>
                    <span style="{{ $small }}">{{ $last['at'] }}</span>
                </div>
                <p style="margin-top:6px">{{ $last['summary'] }}</p>
                <p style="{{ $small }}">
                    {{ __('knowledge::map.run.cost', ['cost' => number_format($last['cost'], 4)]) }}
                    @if ($last['took_ms'] !== null) · {{ __('knowledge::map.run.took', ['s' => number_format($last['took_ms'] / 1000, 1)]) }} @endif
                    @if (! empty($last['output']['embedding']['stopped'] ?? null))
                        · <span style="color:#b45309">{{ __('knowledge::map.stopped.'.$last['output']['embedding']['stopped']) }}</span>
                    @endif
                </p>
            @endif
        </div>
        <div style="flex:1 1 240px">
            <div style="{{ $label }}">{{ __('knowledge::map.run.spend') }}</div>
            <div style="{{ $bar($spend['cap'] > 0 ? $spend['spent'] / $spend['cap'] : 0, '#b45309') }}"></div>
            <div style="font-size:12px;margin-top:4px">
                {{ __('knowledge::map.run.spent', ['spent' => number_format($spend['spent'], 2), 'cap' => number_format($spend['cap'], 2)]) }}
            </div>
            <div style="{{ $small }}">{{ __('knowledge::map.run.share', ['share' => (int) round($spend['share'] * 100)]) }}</div>
        </div>
    </div>
</x-filament::section>
