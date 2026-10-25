{{-- The demo in its phone (DemoController::phone): what the demo is, in the store's own language and Lets' look and
     fonts, with Lets' floating search on it (which takes the page's font, so Lets' too). Framed by the demo page; on a
     phone it fills the screen. A picture of the store's home page came out poorly, so none is shown. --}}
@php($language = $site->choices['language'] ?? app()->getLocale())
@php($say = fn (string $key, array $params = []) => __('demo::page.view.'.$key, $params, $language))
@php($limit = (int) \App\Core\Facades\Settings::get('demo.max_products'))
<!doctype html>
<html lang="{{ $language }}" dir="{{ $language === 'he' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $site->title ?? $site->host }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Google+Sans:wght@400;500;600&family=Heebo:wght@400;500;600&display=swap">
    <style>
        :root { --ink: #0b0b0c; --muted: #5f5f67; --lime: #e2ff78; --ground: #f6f6f7; --line: #e2e2e6; }
        * { box-sizing: border-box; }
        html, body { height: 100%; }
        /* a phone shows no scrollbar */
        html { scrollbar-width: none; }
        html::-webkit-scrollbar { display: none; }
        body { margin: 0; background: var(--ground); color: var(--ink); font-family: "Google Sans", "Heebo", system-ui, sans-serif; -webkit-font-smoothing: antialiased; overflow-x: hidden; }
        .intro { min-height: 100%; display: flex; flex-direction: column; padding: 28px 24px 140px; }
        .head { position: relative; isolation: isolate; overflow: hidden; margin: -28px -24px 0; padding: 40px 24px 34px; background: var(--ink); color: #fff; }
        .head::before { content: ""; position: absolute; inset: 0; z-index: -1; background-image: linear-gradient(to right, rgb(255 255 255 / .08) 1px, transparent 1px), linear-gradient(to bottom, rgb(255 255 255 / .08) 1px, transparent 1px); background-size: 36px 36px; -webkit-mask: radial-gradient(ellipse 80% 80% at 50% 30%, #000 20%, transparent 75%); mask: radial-gradient(ellipse 80% 80% at 50% 30%, #000 20%, transparent 75%); }
        .head::after { content: ""; position: absolute; inset-inline: -30%; bottom: -60%; height: 90%; z-index: -1; border-radius: 50%; background: radial-gradient(closest-side, rgb(226 255 120 / .28), transparent); }
        .brand { display: inline-flex; align-items: center; gap: 7px; font-weight: 600; font-size: 15px; }
        .brand i { width: 11px; height: 14px; display: inline-grid; grid-template-rows: 1fr 1fr; }
        .brand i::before, .brand i::after { content: ""; background: var(--lime); }
        .brand i::before { border-radius: 0 0 50% 50% / 0 0 100% 100%; }
        .brand i::after { border-radius: 50% 50% 0 0 / 100% 100% 0 0; }
        h1 { margin: 26px 0 0; font-size: 32px; line-height: 1.08; letter-spacing: -.035em; font-weight: 500; }
        h1 em { font-style: normal; color: var(--lime); }
        .lede { margin: 12px 0 0; color: #a9a9b2; font-size: 15px; line-height: 1.5; }
        .card { margin-top: 18px; padding: 18px; border-radius: 22px; background: #fff; box-shadow: 0 1px 0 var(--line); }
        .card b { display: block; font-weight: 500; font-size: 15px; }
        .card p { margin: 6px 0 0; color: var(--muted); font-size: 14px; line-height: 1.55; }
        .hint { position: fixed; z-index: 4; bottom: 92px; inset-inline-end: 16px; max-width: 230px; padding: 12px 16px; border-radius: 18px; background: var(--ink); color: #fff; font-size: 14px; line-height: 1.4; box-shadow: 0 16px 40px -16px rgb(0 0 0 / .45); }
        .hint::after { content: ""; position: absolute; bottom: -7px; inset-inline-end: 24px; width: 14px; height: 14px; background: var(--ink); transform: rotate(45deg); }
    </style>
</head>
<body>
    <main class="intro" data-intro>
        <div class="head">
            <span class="brand"><i aria-hidden="true"></i>Lets</span>
            <h1>{{ $say('side_title', ['site' => $site->title ?? $site->host]) }}</h1>
            <p class="lede">{{ $say('intro') }}</p>
        </div>
        {{-- a demo, so maybe partial: how much of the store it read when the store says how much it has --}}
        <div class="card" data-partial>
            <b>{{ $say('demo_note') }}</b>
            <p>
                @if ($site->store_products && $site->store_products > $site->products)
                    {{ $say('demo_read', ['read' => number_format($site->products), 'total' => number_format($site->store_products)]) }}
                @elseif ($site->products >= $limit)
                    {{ $say('demo_limit', ['limit' => number_format($limit)]) }}
                @endif
                {{ $say('full_scan') }}
            </p>
        </div>
    </main>

    {{-- in the store's language and its direction, as the search beside it: its arrow at the button --}}
    <div class="hint" data-hint>{{ $say('hint') }}</div>

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
