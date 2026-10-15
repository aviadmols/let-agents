@php
    $t = fn (string $key, array $params = []) => __('search::share.'.$key, $params, $locale);
    $money = fn ($amount, $currency = 'ILS') => ($currency === 'ILS' ? '₪' : $currency.' ').number_format((float) $amount, (float) $amount == floor((float) $amount) ? 0 : 2);
@endphp
<!doctype html>
<html lang="{{ $locale }}" dir="{{ $locale === 'he' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    @if ($share === null)
        <title>{{ $t('gone') }}</title>
    @else
        @php($heading = $s['query'] !== '' ? $t('title', ['store' => $s['store']['name'], 'query' => $s['query']]) : $t('title_no_query', ['store' => $s['store']['name']]))
        <title>{{ $heading }}</title>
        {{-- The preview WhatsApp, Facebook or a chat shows where the link is shared. --}}
        <meta property="og:type" content="website">
        <meta property="og:title" content="{{ $heading }}">
        <meta property="og:description" content="{{ $t('preview', ['count' => count($s['products']), 'store' => $s['store']['name']]) }}">
        @if (! empty($s['products'][0]['image']))<meta property="og:image" content="{{ $s['products'][0]['image'] }}">@endif
        <meta property="og:site_name" content="{{ $s['store']['name'] }}">
        <meta name="twitter:card" content="summary_large_image">
        <script type="application/ld+json">{!! json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'name' => $s['query'],
            'itemListElement' => array_map(fn (array $p, int $i): array => ['@type' => 'ListItem', 'position' => $i + 1, 'item' => array_filter([
                '@type' => 'Product',
                'name' => $p['title'],
                'url' => $p['url'] ?? null,
                'image' => $p['image'] ?? null,
                'sku' => $p['sku'] ?? null,
                'brand' => isset($p['brand']) ? ['@type' => 'Brand', 'name' => $p['brand']] : null,
                'description' => $p['description'] ?? null,
                'additionalProperty' => array_map(fn (string $name, array $values): array => ['@type' => 'PropertyValue', 'name' => $name, 'value' => implode(', ', $values)], array_keys($p['details'] ?? []), $p['details'] ?? []),
                'offers' => isset($p['price']) ? ['@type' => 'Offer', 'price' => $p['price'], 'priceCurrency' => $p['currency'] ?? 'ILS', 'availability' => ($p['in_stock'] ?? false) ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock', 'url' => $p['url'] ?? null] : null,
            ])], $s['products'], array_keys($s['products'])),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
    @endif
    <style>
        :root { --fg: #111; --muted: #666; --line: #e5e5e5; --bg: #fff; --soft: #f6f6f4; }
        @media (prefers-color-scheme: dark) { :root { --fg: #eee; --muted: #9a9a9a; --line: #2c2c2c; --bg: #141414; --soft: #1d1d1d; } }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--fg); font: 16px/1.6 system-ui, -apple-system, "Segoe UI", Heebo, Arial, sans-serif; }
        main { max-width: 860px; margin: 0 auto; padding: 32px 16px 64px; }
        h1 { font-size: 26px; line-height: 1.25; margin: 0 0 8px; }
        h2 { font-size: 19px; margin: 36px 0 12px; }
        h3 { font-size: 17px; margin: 0 0 6px; }
        p { margin: 0 0 10px; }
        .muted { color: var(--muted); font-size: 14px; }
        .ai { background: var(--soft); border-radius: 10px; padding: 12px 14px; font-size: 14px; color: var(--muted); }
        .chips { display: flex; flex-wrap: wrap; gap: 8px; list-style: none; padding: 0; margin: 0; }
        .chips li { border: 1px solid var(--line); border-radius: 999px; padding: 4px 12px; font-size: 14px; }
        article { display: grid; grid-template-columns: 110px 1fr; gap: 16px; padding: 18px 0; border-top: 1px solid var(--line); }
        article img { width: 110px; height: 110px; object-fit: cover; border-radius: 8px; background: var(--soft); }
        .price { font-weight: 700; }
        .was { text-decoration: line-through; color: var(--muted); font-weight: 400; margin-inline-start: 6px; }
        dl { display: grid; grid-template-columns: max-content 1fr; gap: 2px 12px; margin: 8px 0; font-size: 14px; }
        dt { color: var(--muted); }
        dd { margin: 0; }
        a { color: inherit; }
        footer { margin-top: 48px; font-size: 13px; color: var(--muted); }
        .go { display: inline-block; background: var(--fg); color: var(--bg); text-decoration: none; font-weight: 600; padding: 10px 18px; border-radius: 8px; margin: 4px 0 8px; }
        @media (max-width: 520px) { article { grid-template-columns: 1fr; } article img { width: 100%; height: auto; aspect-ratio: 1; } }
    </style>
</head>
<body>
<main>
    @if ($share === null)
        <h1>{{ $t('gone') }}</h1>
        <p class="muted">{{ $t('gone_help') }}</p>
    @else
        @php($time = \Illuminate\Support\Carbon::parse($s['as_of'])->timezone('Asia/Jerusalem')->format('d.m.Y H:i'))
        <h1>{{ $s['query'] !== '' ? $t('title', ['store' => $s['store']['name'], 'query' => $s['query']]) : $t('title_no_query', ['store' => $s['store']['name']]) }}</h1>
        <p class="muted">{{ $t('intro', ['time' => $time]) }}</p>
        @if ($storeSearch)<p><a class="go" href="{{ $storeSearch }}" rel="noopener">{{ $t('on_store', ['store' => $s['store']['name']]) }}</a></p>@endif
        <p class="ai">{{ $t('for_ai', ['json' => $json]) }}@if ($connector) {{ $t('connector', ['url' => $connector]) }}@endif</p>

        @php($read = $s['read'] ?? [])
        @if (($read['min_price'] ?? null) !== null || ($read['max_price'] ?? null) !== null || ($read['filters'] ?? []) !== [])
            <h2>{{ $t('read') }}</h2>
            <ul class="chips">
                @if (($read['min_price'] ?? null) !== null && ($read['max_price'] ?? null) !== null)
                    <li>{{ $t('between', ['min' => $money($read['min_price']), 'max' => $money($read['max_price'])]) }}</li>
                @elseif (($read['max_price'] ?? null) !== null)
                    <li>{{ $t('up_to', ['amount' => $money($read['max_price'])]) }}</li>
                @elseif (($read['min_price'] ?? null) !== null)
                    <li>{{ $t('from', ['amount' => $money($read['min_price'])]) }}</li>
                @endif
                @foreach ($read['filters'] ?? [] as $filter)
                    <li>{{ $filter['name'] }}: {{ $filter['value'] }}</li>
                @endforeach
            </ul>
        @endif

        <h2>{{ $t('products', ['count' => count($s['products'])]) }}</h2>
        @forelse ($s['products'] as $p)
            <article>
                @if (! empty($p['image']))<img src="{{ $p['image'] }}" alt="{{ $p['title'] }}" loading="lazy">@else<span></span>@endif
                <div>
                    <h3>{{ $p['title'] }}</h3>
                    @isset($p['price'])
                        <p><span class="price">{{ $money($p['price'], $p['currency'] ?? 'ILS') }}</span>@isset($p['regular_price'])<span class="was">{{ $t('was', ['amount' => $money($p['regular_price'], $p['currency'] ?? 'ILS')]) }}</span>@endisset
                            · {{ ($p['in_stock'] ?? false) ? $t('in_stock') : $t('out_of_stock') }}</p>
                    @endisset
                    <dl>
                        @isset($p['brand'])<dt>{{ $t('brand') }}</dt><dd>{{ $p['brand'] }}</dd>@endisset
                        @isset($p['sku'])<dt>{{ $t('sku') }}</dt><dd>{{ $p['sku'] }}</dd>@endisset
                        @foreach ($p['details'] ?? [] as $name => $values)
                            <dt>{{ $name }}</dt><dd>{{ implode(', ', $values) }}</dd>
                        @endforeach
                        @if (! empty($p['variants']))
                            <dt>{{ $t('variants') }}</dt>
                            <dd>{{ collect($p['variants'])->map(fn ($v) => $v['title'].(isset($v['price']) ? ' – '.$money($v['price'], $p['currency'] ?? 'ILS') : '').($v['in_stock'] ? '' : ' ('.$t('out_of_stock').')'))->implode(' · ') }}</dd>
                        @endif
                    </dl>
                    @isset($p['description'])<p class="muted">{{ $p['description'] }}</p>@endisset
                    @isset($p['url'])<p><a href="{{ $p['url'] }}" rel="noopener">{{ $t('to_product') }}</a></p>@endisset
                </div>
            </article>
        @empty
            <p>{{ $t('none') }}</p>
        @endforelse

        @php($policies = $s['policies'] ?? [])
        @if (($policies['promises'] ?? []) !== [] || ($policies['pages'] ?? []) !== [])
            <h2>{{ $t('policies') }}</h2>
            @if (($policies['promises'] ?? []) !== [])
                <dl>
                    @foreach ($policies['promises'] as $promise)
                        <dt>{{ $promise['name'] }}</dt><dd>{{ $promise['detail'] }}</dd>
                    @endforeach
                </dl>
            @endif
            @if (($policies['pages'] ?? []) !== [])
                <p class="muted">{{ $t('policy_pages') }}:
                    @foreach ($policies['pages'] as $page)<a href="{{ $page['url'] }}" rel="noopener">{{ $page['title'] }}</a>@if (! $loop->last) · @endif @endforeach
                </p>
            @endif
        @endif

        <footer>{{ $s['store']['name'] }} · <a href="{{ $s['store']['url'] }}" rel="noopener">{{ $s['store']['url'] }}</a> · {{ $t('by') }}</footer>
    @endif
</main>
</body>
</html>
