<x-filament-panels::page>
    {{-- Settings · Manual theme connection (ManualConnectSettings), as the designer's panel lays it out. --}}
    @php($t = fn (string $key, array $params = []) => __('search::shop_settings.manual.'.$key, $params))
    @php($status = $this->status())
    @php($shopify = $status['platform'] === 'shopify')
    @php($on = $shopify ? $status['seen'] : $status['connected'])

    <x-lp::card :title="$t('title')" :help="$t('help')">
        <x-slot:head>
            @if ($status['seen'])
                <span class="lp-pill" data-tone="good"><i></i>{{ $t('caught') }}</span>
            @else
                <span class="lp-pill" data-tone="warn"><i></i>{{ $t('not_seen') }}</span>
            @endif
        </x-slot:head>

        <p class="lp-set-help" style="margin: 0 0 12px">{{ $t('intro') }}</p>

        <div class="lp-fact" data-platform="{{ $status['platform'] }}" style="margin-bottom: 12px">
            @if ($on)
                <svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true" style="flex: none"><path d="M5 12.5l4.2 4.2L19 7" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            @else
                <svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true" style="flex: none"><circle cx="12" cy="12" r="8" fill="none" stroke="currentColor" stroke-width="2"/><path d="M12 8v5M12 16h.01" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
            @endif
            <span>
                @if ($shopify)
                    <b style="display: block">{{ $t($on ? 'shopify_on' : 'shopify_off') }}</b>{{ $t('shopify_where') }}
                @else
                    <b style="display: block">{{ $t($on ? 'woo_on' : 'woo_off') }}</b>@if ($status['version']){{ $t('woo_version', ['version' => $status['version']]) }} · @endif{{ $t('woo_where') }}@if ($status['checked']) <span class="lp-sample">· {{ $t('checked', ['when' => $status['checked']]) }}</span>@endif
                @endif
            </span>
        </div>

        <x-lp::kv>
            <x-lp::kv-row :label="$t('selector')" :tip="$t('selector_tip')" :error="$this->problem('selector')">
                <x-lp::field model="data.selector" :ltr="true" placeholder="input.header__search-input, #search-field" />
            </x-lp::kv-row>
            <x-lp::kv-row :label="$t('own')"><code class="lp-snip">{{ $t('own_snip') }}</code></x-lp::kv-row>
            <x-lp::kv-row :label="$t('button')"><code class="lp-snip">{{ $t('button_snip') }}</code></x-lp::kv-row>
        </x-lp::kv>
    </x-lp::card>

    <x-lp::save />
</x-filament-panels::page>
