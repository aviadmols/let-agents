@php($t = fn (string $key, array $params = []) => __('search::share.'.$key, $params, $locale))
<!doctype html>
<html lang="{{ $locale }}" dir="{{ $locale === 'he' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $t('cart_title', ['store' => $store['name']]) }}</title>
    @include('search::partials.style')
</head>
<body>
<main>
    <h1>{{ $t('cart_title', ['store' => $store['name']]) }}</h1>

    @foreach ($cart['problems'] as $problem)
        <section style="margin: 16px 0">
            @if (in_array($problem['problem'], ['choose_option', 'unknown_option'], true))
                <h2>{{ $t('cart_choose') }}</h2>
                <ul class="options">
                    @foreach ($problem['options'] ?? [] as $option)
                        <li>
                            @if ($option['in_stock'])
                                <a class="buy" href="{{ $chosen($problem['product'], $option['id']) }}">{{ $option['title'] }}</a>
                            @else
                                {{ $option['title'] }} <span class="muted">({{ $t('out_of_stock') }})</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @else
                <p>{{ $t('cart_problem_'.$problem['problem']) }} ({{ $problem['product'] }})</p>
            @endif
        </section>
    @endforeach

    @if ($cart['problems'] === [] && count($cart['links']) > 1)
        {{-- WooCommerce adds one product a link: each in turn. --}}
        <h2>{{ $t('cart_each') }}</h2>
        <ul class="options">
            @foreach ($cart['links'] as $link)
                <li><a class="buy" href="{{ $link['url'] }}" target="_blank" rel="noopener">{{ $t('buy') }} ({{ $link['product'] }})</a></li>
            @endforeach
        </ul>
    @endif

    @if ($cart['links'] === [] && $cart['problems'] === [])
        <p>{{ $t('cart_empty') }}</p>
    @endif

    <footer>{{ $store['name'] }} · <a href="{{ $store['url'] }}" rel="noopener">{{ $store['url'] }}</a></footer>
</main>
</body>
</html>
