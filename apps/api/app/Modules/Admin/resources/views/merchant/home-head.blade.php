{{-- Today's heading row, as the designer's redesign draws it: the greeting and how the period went, one sentence, the period and "See it as a shopper". --}}
@php($t = fn (string $key, array $params = []) => __('admin::today.'.$key, $params))
<header class="lp-d-top" data-home-head>
    <div>
        <h1 class="lp-d-hello">{{ $title }}. <em>{{ $mood }}</em></h1>
        <p class="lp-d-lede">{{ $lede }}</p>
    </div>
    <div class="lp-d-top-tools">
        @include('admin::merchant.period')
        @if ($this->storeUrl())
            <a class="lp-d-btn lp-d-btn-line" href="{{ $this->storeUrl() }}" target="_blank" rel="noopener" wire:click="looked" data-see-as-shopper>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/></svg>
                <span>{{ $t('see') }}</span>
            </a>
        @endif
    </div>
</header>
