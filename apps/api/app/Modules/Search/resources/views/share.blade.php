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
        @include('search::partials.ld', ['products' => $s['products'], 'name' => $s['query']])
    @endif
    @include('search::partials.style')
</head>
<body>
<main>
    @if ($share === null)
        <h1>{{ $t('gone') }}</h1>
        <p class="muted">{{ $t('gone_help') }}</p>
    @else
        @php($time = \Illuminate\Support\Carbon::parse($s['as_of'])->timezone('Asia/Jerusalem')->format('d.m.Y H:i'))
        <h1>{{ $heading }}</h1>
        <p class="muted">{{ $t('intro', ['time' => $time]) }}</p>
        @if ($storeSearch)<p><a class="go" href="{{ $storeSearch }}" rel="noopener">{{ $t('on_store', ['store' => $s['store']['name']]) }}</a></p>@endif
        @php($ai = \App\Modules\Search\Support\AgentCatalog::forAi($s['store'], $s['products'], (string) $s['as_of'], array_filter(['browse' => $browse, 'query' => $s['query'], 'policies' => $s['policies'] ?? null])))
        @include('search::partials.guide', ['store' => $s['store'], 'lines' => $ai['for_ai'] ?? []])
        @include('search::partials.browse', ['browse' => $browse])
        @include('search::partials.json', ['ai' => $ai, 'json' => $json])

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
        @if ($s['products'] === [])
            <p>{{ $t('none') }}</p>
        @else
            @include('search::partials.products', ['products' => $s['products']])
        @endif

        @include('search::partials.policies', ['policies' => $s['policies'] ?? []])

        <footer>{{ $s['store']['name'] }} · <a href="{{ $s['store']['url'] }}" rel="noopener">{{ $s['store']['url'] }}</a> · {{ $t('by') }}</footer>
    @endif
</main>
</body>
</html>
