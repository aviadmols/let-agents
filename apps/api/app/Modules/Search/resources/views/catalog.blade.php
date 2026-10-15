@php
    $t = fn (string $key, array $params = []) => __('search::share.'.$key, $params, $locale);
    $money = fn ($amount, $currency = 'ILS') => ($currency === 'ILS' ? '₪' : $currency.' ').number_format((float) $amount, (float) $amount == floor((float) $amount) ? 0 : 2);
    $time = \Illuminate\Support\Carbon::parse($c['as_of'])->timezone('Asia/Jerusalem')->format('d.m.Y H:i');
    $heading = $t('catalog_title', ['store' => $store['name']]);
    $at = fn (int $page): string => $store['catalog'].'?'.http_build_query(array_filter(['q' => $c['query'] ?: null, 'page' => $page > 1 ? $page : null]));
@endphp
<!doctype html>
<html lang="{{ $locale }}" dir="{{ $locale === 'he' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $c['query'] !== '' ? $c['query'].' · '.$heading : $heading }}</title>
    <meta property="og:type" content="website">
    <meta property="og:title" content="{{ $heading }}">
    @if (! empty($c['products'][0]['image']))<meta property="og:image" content="{{ $c['products'][0]['image'] }}">@endif
    @include('search::partials.ld', ['products' => $c['products'], 'name' => $c['query'] ?: $heading])
    @include('search::partials.style')
</head>
<body>
<main>
    <h1>{{ $heading }}</h1>
    @include('search::partials.guide', ['store' => $store, 'time' => $time, 'connector' => $connector])
    @include('search::partials.browse', ['browse' => $ai['browse'] ?? null])
    @include('search::partials.json', ['ai' => $ai, 'json' => $at($c['page']).(str_contains($at($c['page']), '?') ? '&' : '?').'format=json'])

    <form method="get" action="{{ $store['catalog'] }}" role="search">
        <input type="search" name="q" value="{{ $c['query'] }}" aria-label="{{ $t('search_label') }}" placeholder="{{ $t('search_label') }}">
        <button type="submit">{{ $t('search_button') }}</button>
    </form>

    <h2>{{ $c['query'] !== '' ? $t('catalog_found', ['total' => number_format($c['total']), 'query' => $c['query']]) : $t('catalog_all', ['total' => number_format($c['total'])]) }}</h2>
    @php($read = $c['read'] ?? [])
    @if (($read['min_price'] ?? null) !== null || ($read['max_price'] ?? null) !== null || ($read['filters'] ?? []) !== [])
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

    @if ($c['products'] === [])
        <p>{{ $t('none') }}</p>
    @else
        @include('search::partials.products', ['products' => $c['products']])
    @endif

    <nav class="pages">
        <span>@if ($c['page'] > 1)<a href="{{ $at($c['page'] - 1) }}">{{ $t('previous') }}</a>@endif</span>
        <span class="muted">{{ $t('page_of', ['page' => $c['page'], 'pages' => $c['pages']]) }}</span>
        <span>@if ($c['page'] < $c['pages'])<a href="{{ $at($c['page'] + 1) }}">{{ $t('next') }}</a>@endif</span>
    </nav>

    @if ($c['page'] === 1)
        @include('search::partials.policies', ['policies' => $policies])
    @endif

    <footer>{{ $store['name'] }} · <a href="{{ $store['url'] }}" rel="noopener">{{ $store['url'] }}</a> · {{ $t('by') }}</footer>
</main>
</body>
</html>
