{{-- The demo: the store's own home page as a picture (a computer's or a phone's), with Lets' floating search on it, the
     real drawer on the demo store (DemoController::show, ready). A thin bar says it is a demo, until when, and how to connect. --}}
@php($t = fn (string $key, array $params = []) => __('demo::page.'.$key, $params))
@php($until = $site->expires_at?->locale(app()->getLocale())->translatedFormat('j.n.Y'))
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'he' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $t('view.title', ['site' => $site->title ?? $site->host]) }}</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Google+Sans:wght@400;500;600&family=Heebo:wght@400;500;600&display=swap">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #f6f6f7; font-family: "Google Sans", "Heebo", system-ui, sans-serif; }
        .bar { position: sticky; top: 0; z-index: 5; display: flex; align-items: center; gap: 14px; flex-wrap: wrap; padding: 10px 18px; background: #0b0b0c; color: #fff; font-size: 14px; }
        .bar b { font-weight: 600; }
        .bar .mark { width: 12px; height: 16px; display: inline-grid; grid-template-rows: 1fr 1fr; vertical-align: -2px; margin-inline-end: 6px; }
        .bar .mark::before, .bar .mark::after { content: ""; background: #e2ff78; }
        .bar .mark::before { border-radius: 0 0 50% 50% / 0 0 100% 100%; }
        .bar .mark::after { border-radius: 50% 50% 0 0 / 100% 100% 0 0; }
        .bar span.muted { color: #a9a9b2; }
        .bar a.cta { margin-inline-start: auto; padding: 8px 16px; border-radius: 999px; background: #e2ff78; color: #0b0b0c; text-decoration: none; font-weight: 600; white-space: nowrap; }
        .site { display: block; width: 100%; max-width: 1366px; margin: 0 auto; }
        .site img { display: block; width: 100%; height: auto; }
        .site .desk { display: block; } .site .phone { display: none; }
        @media (max-width: 700px) { .site .desk { display: none; } .site .phone { display: block; } .bar span.muted { display: none; } }
        .empty { display: grid; place-items: center; min-height: 70vh; color: #5f5f67; text-align: center; padding: 24px; }
        .hint { position: fixed; z-index: 4; bottom: 96px; inset-inline-end: 22px; max-width: 220px; padding: 12px 16px; border-radius: 18px; background: #0b0b0c; color: #fff; font-size: 14px; box-shadow: 0 16px 40px -16px rgb(0 0 0 / .45); }
        .hint::after { content: ""; position: absolute; bottom: -7px; inset-inline-end: 26px; width: 14px; height: 14px; background: #0b0b0c; transform: rotate(45deg); }
    </style>
</head>
<body>
    <div class="bar" data-demo-bar>
        <span><span class="mark" aria-hidden="true"></span><b>{{ $t('view.bar', ['site' => $site->title ?? $site->host]) }}</b></span>
        <span class="muted">{{ $t('view.until', ['date' => $until ?? '']) }}</span>
        <a class="cta" href="{{ $t('view.cta_url') }}" target="_blank" rel="noopener">{{ $t('view.cta') }}</a>
    </div>

    <main class="site" aria-label="{{ $site->title ?? $site->host }}">
        @if ($site->screenshot || $site->screenshot_mobile)
            @if ($site->screenshot)<img class="desk" src="{{ route('demo.shot', [$site->token, 'desktop']) }}" alt="{{ $site->title ?? $site->host }}">@endif
            <img class="phone" src="{{ route('demo.shot', [$site->token, $site->screenshot_mobile ? 'mobile' : 'desktop']) }}" alt="{{ $site->title ?? $site->host }}">
        @else
            <div class="empty"><div><h2 style="color: #0b0b0c; font-weight: 500">{{ $site->title ?? $site->host }}</h2><p>{{ $t('view.no_picture') }}</p></div></div>
        @endif
    </main>

    <div class="hint" data-hint>{{ $t('view.hint') }}</div>

    @if ($siteKey)
        <script>
            window.LetsSearchConfig = { site: @json($siteKey), api: @json(url('/api/v1')), locale: @json($site->choices['language'] ?? app()->getLocale()), launcher: 'float' };
            // the hint goes once the search was opened
            document.addEventListener('click', function (e) { if (e.target && e.target.closest && !e.target.closest('[data-demo-bar]')) { var h = document.querySelector('[data-hint]'); if (h) { h.remove(); } } }, true);
        </script>
        <script src="{{ route('api.search.lets-search') }}" defer></script>
    @endif
</body>
</html>
