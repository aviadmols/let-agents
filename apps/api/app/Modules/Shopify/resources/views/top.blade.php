<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ \App\Core\Localization\Locales::direction(app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Inside the Shopify admin's frame, the next step (Shopify's own screens) must open in the full window. --}}
    <meta name="shopify-api-key" content="{{ $key }}">
    {{-- the browser's own window.open, before App Bridge takes it over: App Bridge holds every _top open until it has
         reached the admin, and silently forever when it never does --}}
    <script>window.__letAgentsOpen = window.open ? window.open.bind(window) : null;</script>
    <script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>
    <title>{{ __('shopify::app.continue_title') }}</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; font: 15px/1.6 system-ui, Heebo, sans-serif; background: #f7f7f8; color: #5f6368; }
        main { display: grid; justify-items: center; gap: 14px; text-align: center; padding: 16px; }
        .spin { width: 28px; height: 28px; border: 3px solid #e4e4e7; border-top-color: #111; border-radius: 50%; animation: spin .8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        p { margin: 0; }
        button { padding: 10px 18px; border: 0; border-radius: 999px; background: #111; color: #fff; font: inherit; font-weight: 600; cursor: pointer; }
        [hidden] { display: none !important; }
    </style>
</head>
<body>
{{-- A step on the way, not a screen: a moment while Shopify opens its approval; the button only if it did not. --}}
<main>
    <div class="spin" aria-hidden="true"></div>
    <p>{{ __('shopify::app.continue_text') }}</p>
    <button type="button" data-continue hidden>{{ __('shopify::app.continue') }}</button>
</main>
<script>
    (function () {
        var url = @json($url), reached = false, gone = false;
        var button = document.querySelector('[data-continue]');
        // App Bridge takes the admin's window to Shopify's screen (window.open with _top), once it has reached the admin:
        // a session token proves it has.
        function go() { if (reached && !gone) { gone = true; window.open(url, '_top'); } }
        try {
            if (window.shopify && window.shopify.idToken) {
                window.shopify.idToken().then(function (token) { reached = typeof token === 'string' && token !== ''; go(); }, function () {});
            }
        } catch (e) {}
        setTimeout(function () { button.hidden = false; }, 3000);
        // A press: through App Bridge when it reached the admin; otherwise Shopify's approval in a tab of its own, by the
        // browser itself (the admin's frame lets a press open one), and once approved it comes back into the app there.
        button.addEventListener('click', function () {
            if (reached) { gone = false; go(); return; }
            var open = window.__letAgentsOpen;
            var tab = open ? open(url, '_blank') : null;
            if (!tab) { try { window.top.location.href = url; } catch (e) { location.href = url; } }
        });
    })();
</script>
</body>
</html>
