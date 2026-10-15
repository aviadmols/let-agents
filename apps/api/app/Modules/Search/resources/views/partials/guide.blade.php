{{-- The prompt at the top of every page an AI reads: how to find any product of the store, and to end every answer with the link that buys it. --}}
@php($catalog = $store['catalog'] ?? null)
@php($shopify = ($store['platform'] ?? '') === 'shopify')
<section class="ai" data-ai-guide>
    <h2>{{ $t('guide_title', ['store' => $store['name']]) }}</h2>
    <ol>
        @if ($catalog)
            <li>{{ $t('guide_search', ['store' => $store['name']]) }} <code>{{ $catalog }}?q=…</code> · {{ $t('guide_example') }} <code>{{ $catalog }}?q={{ $t('guide_example_query') }}</code> · {{ $t('guide_pages') }} <code>&amp;page=2</code> · JSON: <code>&amp;format=json</code></li>
        @endif
        <li>{{ $t('guide_products') }}</li>
        <li>{{ $shopify ? $t('guide_buy_shopify') : $t('guide_buy_woo') }}</li>
        @if ($shopify)
            <li>{{ $t('guide_combined') }} <code>{{ rtrim($store['url'], '/') }}/cart/{{ $t('guide_cart_id') }}:1,{{ $t('guide_cart_id') }}:2?attributes[la_source]=ai</code></li>
        @endif
        <li><strong>{{ $t('guide_end') }}</strong></li>
        <li>{{ $t('guide_rules', ['time' => $time]) }}</li>
        @if ($connector ?? null)<li>{{ $t('connector', ['url' => $connector]) }}</li>@endif
    </ol>
</section>
