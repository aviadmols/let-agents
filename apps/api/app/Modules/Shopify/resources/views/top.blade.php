<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ \App\Core\Localization\Locales::direction(app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Inside the Shopify admin's frame, the next step (Shopify's own screens) must open in the full window. --}}
    <meta name="shopify-api-key" content="{{ $key }}">
    <script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>
    <title>{{ __('shopify::app.continue_title') }}</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; font: 16px/1.6 system-ui, Heebo, sans-serif; background: #f7f7f8; color: #111; }
        main { max-width: 440px; padding: 32px; background: #fff; border-radius: 16px; box-shadow: 0 10px 40px rgba(0,0,0,.08); text-align: center; }
        a { display: inline-block; margin-top: 16px; padding: 10px 18px; border-radius: 999px; background: #111; color: #fff; text-decoration: none; font-weight: 600; }
    </style>
</head>
<body>
<main>
    <p>{{ __('shopify::app.continue_text') }}</p>
    {{-- a press takes the window there too, through App Bridge, whatever stopped the step on its own --}}
    <a href="{{ $url }}" target="_top" data-continue onclick="event.preventDefault(); window.open(this.href, '_top');">{{ __('shopify::app.continue') }}</a>
</main>
{{-- App Bridge (in the head, opened with host) takes the admin's window to Shopify's screen: window.open with _top is its
     way out of the frame; at once, and again once it is ready --}}
<script>
    (function () {
        var url = @json($url), gone = false;
        function go() { if (!gone) { gone = true; window.open(url, '_top'); } }
        if (window.shopify && window.shopify.ready) { window.shopify.ready.then(go, go); } else { go(); }
        setTimeout(function () { gone = false; go(); }, 1500);
    })();
</script>
</body>
</html>
