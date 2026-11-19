{{-- Today's heading row, as the designer's redesign draws it: the greeting and how the period went, one sentence, the period and "See it as a shopper". --}}
@php($t = fn (string $key, array $params = []) => __('admin::today.'.$key, $params))
<header class="lp-d-top" data-home-head>
    <div>
        {{-- the greeting by the clock of the person reading it; the store's own until the page runs (App Store review, 19.11:
             "Good morning" at 17:08 for a reviewer far from the store) --}}
        <h1 class="lp-d-hello"><span x-data="{ g: @js($this->greetings()), part() { const h = new Date().getHours(); return h < 5 ? 'night' : (h < 12 ? 'morning' : (h < 17 ? 'noon' : (h < 21 ? 'evening' : 'night'))); } }" x-text="g[part()] + '.'">{{ $title }}.</span> <em>{{ $mood }}</em></h1>
        <p class="lp-d-lede">{{ $lede }}</p>
    </div>
    <div class="lp-d-top-tools">
        @include('admin::merchant.period')
        @if ($this->storeUrl())
            <a class="lp-d-btn lp-d-btn-line" href="{{ $this->storeUrl() }}" @if ($this->storeOutside()) target="_blank" rel="noopener" @endif wire:click="looked" data-see-as-shopper>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/></svg>
                <span>{{ $t('see') }}</span>
            </a>
        @endif
    </div>
</header>
