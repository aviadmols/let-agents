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

    @if (($cart['on_store'] ?? []) !== [])
        {{-- A store's checkout link replaces the cart: the products it can take go first, then each one chosen on its page. --}}
        <p>{{ $t('cart_steps') }}</p>
        <ol class="steps" data-cart-steps>
            @if ($cart['url'] !== null)
                <li>{{ $t('cart_step_link', ['count' => count($cart['links'])]) }} <a class="buy" href="{{ $cart['url'] }}" target="_blank" rel="noopener">{{ $t('cart_add_all') }}</a></li>
            @endif
            <li>{{ $t('cart_step_page', ['field' => $cart['on_store'][0]['field'] ?? '']) }}
                <ul class="options">
                    @foreach ($cart['on_store'] as $item)
                        <li data-on-store="{{ $item['product'] }}">
                            <strong>{{ $item['title'] }}</strong> × {{ $item['quantity'] }}
                            @if (! empty($item['chosen']))
                                · {{ $t('cart_pick', ['choice' => $item['chosen']]) }}@if (isset($item['price'])) (₪{{ rtrim(rtrim(number_format((float) $item['price'], 2, '.', ''), '0'), '.') }})@endif
                            @elseif (! empty($item['choices']))
                                @if (! empty($item['problem']))<span class="muted">({{ $t('cart_problem_'.$item['problem']) }})</span>@endif
                                · {{ $t('cart_pick_from') }} {{ implode(' · ', array_map(fn (array $c): string => $c['label'].(isset($c['price']) ? ' ₪'.rtrim(rtrim(number_format((float) $c['price'], 2, '.', ''), '0'), '.') : ''), $item['choices'])) }}
                            @endif
                            <a class="buy" href="{{ $item['url'] }}" target="_blank" rel="noopener">{{ $t('cart_open') }}</a>
                        </li>
                    @endforeach
                </ul>
            </li>
            <li>{{ $t('cart_step_pay') }} <a class="buy" href="{{ $cart['cart_url'] }}" rel="noopener">{{ $t('cart_to_cart') }}</a></li>
        </ol>
    @endif

    @if ($cart['problems'] === [] && ($cart['on_store'] ?? []) === [] && count($cart['links']) > 1)
        {{-- WooCommerce adds one product a link: each in turn. --}}
        <h2>{{ $t('cart_each') }}</h2>
        <ul class="options">
            @foreach ($cart['links'] as $link)
                <li><a class="buy" href="{{ $link['url'] }}" target="_blank" rel="noopener">{{ $t('buy') }} ({{ $link['product'] }})</a></li>
            @endforeach
        </ul>
    @endif

    @if ($cart['links'] === [] && $cart['problems'] === [] && ($cart['on_store'] ?? []) === [])
        <p>{{ $t('cart_empty') }}</p>
    @endif

    <footer>{{ $store['name'] }} · <a href="{{ $store['url'] }}" rel="noopener">{{ $store['url'] }}</a></footer>
</main>
</body>
</html>
