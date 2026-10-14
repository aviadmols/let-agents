<x-filament-panels::page>
    @php($c = $this->counts())
    <x-filament::section>
        @if (! $c['on'])
            <p data-try-on-off>{{ __('try_on::try_on.merchant.off') }}</p>
        @else
            <p data-try-on-counts style="font-size:15px">{{ __('try_on::try_on.merchant.counts', ['tries' => $c['tries'], 'ready' => $c['ready']]) }}</p>
        @endif
    </x-filament::section>
</x-filament-panels::page>
