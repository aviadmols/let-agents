{{-- The demo: the store's home page in a phone, with Lets' floating search in it, the real drawer on the demo store.
     The phone is a frame of its own page (DemoController::phone), so the search opens in it as it would on a phone:
     on a computer a phone stands in the middle of the page; on a phone it is the whole screen. A thin bar says it is
     a demo, until when, and how to connect. --}}
@php($t = fn (string $key, array $params = []) => __('demo::page.'.$key, $params))
@php($until = $site->expires_at?->locale(app()->getLocale())->translatedFormat('j.n.Y'))
@php($limit = (int) \App\Core\Facades\Settings::get('demo.max_products'))
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
        html, body { height: 100%; }
        body { margin: 0; display: flex; flex-direction: column; background: #f6f6f7; color: #0b0b0c; font-family: "Google Sans", "Heebo", system-ui, sans-serif; }
        .bar { position: relative; z-index: 2; flex: none; display: flex; align-items: center; gap: 14px; flex-wrap: wrap; padding: 10px 18px; background: #0b0b0c; color: #fff; font-size: 14px; }
        .bar b { font-weight: 600; }
        .bar .mark { width: 12px; height: 16px; display: inline-grid; grid-template-rows: 1fr 1fr; vertical-align: -2px; margin-inline-end: 6px; }
        .bar .mark::before, .bar .mark::after { content: ""; background: #e2ff78; }
        .bar .mark::before { border-radius: 0 0 50% 50% / 0 0 100% 100%; }
        .bar .mark::after { border-radius: 50% 50% 0 0 / 100% 100% 0 0; }
        .bar span.muted { color: #a9a9b2; }
        .bar a.cta { margin-inline-start: auto; padding: 8px 16px; border-radius: 999px; background: #e2ff78; color: #0b0b0c; text-decoration: none; font-weight: 600; white-space: nowrap; }
        /* a computer: the phone in the middle, what to try beside it */
        .stage { flex: 1; min-height: 0; display: flex; align-items: center; justify-content: center; gap: 56px; padding: 28px 24px; }
        .phone { flex: none; width: 414px; height: min(868px, calc(100dvh - 120px)); padding: 12px; border-radius: 56px; background: #0b0b0c; box-shadow: 0 40px 80px -30px rgb(11 11 12 / .45), inset 0 0 0 2px #2a2a2e; }
        .phone iframe { display: block; width: 100%; height: 100%; border: 0; border-radius: 44px; background: #fff; }
        .side { max-width: 300px; }
        .side h1 { margin: 0; font-size: 30px; line-height: 1.1; letter-spacing: -.03em; font-weight: 500; }
        .side p { margin: 14px 0 0; color: #5f5f67; font-size: 15px; line-height: 1.55; }
        .side .partial { font-size: 13px; }
        /* a phone: the demo is the whole screen */
        @media (max-width: 700px) {
            .bar { padding: 8px 12px; font-size: 13px; } .bar span.muted { display: none; }
            .stage { padding: 0; gap: 0; }
            .phone { width: 100%; height: 100%; padding: 0; border-radius: 0; box-shadow: none; background: none; }
            .phone iframe { border-radius: 0; }
            .side { display: none; }
        }
    </style>
</head>
<body>
    <div class="bar" data-demo-bar>
        <span><span class="mark" aria-hidden="true"></span><b>{{ $t('view.bar', ['site' => $site->title ?? $site->host]) }}</b></span>
        <span class="muted">{{ $t('view.until', ['date' => $until ?? '']) }}</span>
        <a class="cta" href="{{ $t('view.cta_url') }}" target="_blank" rel="noopener">{{ $t('view.cta') }}</a>
    </div>

    <main class="stage">
        <div class="phone">
            <iframe src="{{ route('demo.phone', $site->token) }}" title="{{ $site->title ?? $site->host }}" data-demo-phone></iframe>
        </div>
        <aside class="side">
            <h1>{{ $t('view.side_title', ['site' => $site->title ?? $site->host]) }}</h1>
            <p>{{ $t('view.hint') }}</p>
            {{-- a demo, so maybe partial: how much of the store it read when the store says how much it has --}}
            <p class="partial" data-partial>
                {{ $t('view.demo_note') }}
                @if ($site->store_products && $site->store_products > $site->products)
                    {{ $t('view.demo_read', ['read' => number_format($site->products), 'total' => number_format($site->store_products)]) }}
                @elseif ($site->products >= $limit)
                    {{ $t('view.demo_limit', ['limit' => number_format($limit)]) }}
                @endif
                {{ $t('view.full_scan') }}
            </p>
        </aside>
    </main>
</body>
</html>
