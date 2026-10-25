{{-- The demo in its phone (DemoController::phone): the store's home page as a phone shows it, scrolling, with Lets'
     floating search on it, in the store's own language. Framed by the demo page; on a phone it fills the screen. --}}
@php($language = $site->choices['language'] ?? app()->getLocale())
<!doctype html>
<html lang="{{ $language }}" dir="{{ $language === 'he' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $site->title ?? $site->host }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #fff; font-family: "Google Sans", "Heebo", system-ui, sans-serif; overflow-x: hidden; }
        img.site { display: block; width: 100%; height: auto; }
        .empty { display: grid; place-items: center; min-height: 100vh; padding: 24px; color: #5f5f67; text-align: center; }
        .hint { position: fixed; z-index: 4; bottom: 92px; inset-inline-end: 16px; max-width: 220px; padding: 12px 16px; border-radius: 18px; background: #0b0b0c; color: #fff; font-size: 14px; line-height: 1.4; box-shadow: 0 16px 40px -16px rgb(0 0 0 / .45); }
        .hint::after { content: ""; position: absolute; bottom: -7px; inset-inline-end: 24px; width: 14px; height: 14px; background: #0b0b0c; transform: rotate(45deg); }
    </style>
</head>
<body>
    @if ($site->screenshot_mobile || $site->screenshot)
        <img class="site" src="{{ route('demo.shot', [$site->token, $site->screenshot_mobile ? 'mobile' : 'desktop']) }}" alt="{{ $site->title ?? $site->host }}">
    @else
        <div class="empty"><div><h2 style="color: #0b0b0c; font-weight: 500">{{ $site->title ?? $site->host }}</h2><p>{{ __('demo::page.view.no_picture', [], $language) }}</p></div></div>
    @endif

    {{-- in the store's language and its direction, as the search beside it: its arrow at the button --}}
    <div class="hint" data-hint>{{ __('demo::page.view.hint', [], $language) }}</div>

    @if ($siteKey)
        <script>
            window.LetsSearchConfig = { site: @json($siteKey), api: @json(url('/api/v1')), locale: @json($language), launcher: 'float' };
            // the hint goes once the search was opened
            document.addEventListener('click', function () { var h = document.querySelector('[data-hint]'); if (h) { h.remove(); } }, true);
        </script>
        <script src="{{ route('api.search.lets-search') }}" defer></script>
    @endif
</body>
</html>
