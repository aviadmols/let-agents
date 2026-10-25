{{-- The demo in its phone (DemoController::phone): what the demo is, plainly on its screen in the demo's language and
     Lets' fonts, with Lets' floating search on it (which takes the page's font, so Lets' too, and speaks the store's
     language). Framed by the demo page; on a phone it fills the screen. --}}
@php($t = fn (string $key, array $params = []) => __('demo::page.view.'.$key, $params))
@php($limit = (int) \App\Core\Facades\Settings::get('demo.max_products'))
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'he' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $site->title ?? $site->host }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Google+Sans:wght@400;500;600&family=Heebo:wght@400;500;600&display=swap">
    <style>
        * { box-sizing: border-box; }
        html, body { height: 100%; }
        /* a phone shows no scrollbar */
        html { scrollbar-width: none; }
        html::-webkit-scrollbar { display: none; }
        body { margin: 0; background: #f6f6f7; color: #0b0b0c; font-family: "Google Sans", "Heebo", system-ui, sans-serif; -webkit-font-smoothing: antialiased; overflow-x: hidden; }
        .intro { min-height: 100%; display: flex; flex-direction: column; justify-content: center; padding: 40px 28px 120px; }
        h1 { margin: 0; font-size: 30px; line-height: 1.1; letter-spacing: -.03em; font-weight: 500; }
        p { margin: 14px 0 0; color: #5f5f67; font-size: 15px; line-height: 1.55; }
        p.partial { font-size: 13px; }
    </style>
</head>
<body>
    <main class="intro" data-intro>
        <h1>{{ $t('side_title', ['site' => $site->title ?? $site->host]) }}</h1>
        <p>{{ $t('hint') }}</p>
        {{-- a demo, so maybe partial: how much of the store it read when the store says how much it has --}}
        <p class="partial" data-partial>
            {{ $t('demo_note') }}
            @if ($site->store_products && $site->store_products > $site->products)
                {{ $t('demo_read', ['read' => number_format($site->products), 'total' => number_format($site->store_products)]) }}
            @elseif ($site->products >= $limit)
                {{ $t('demo_limit', ['limit' => number_format($limit)]) }}
            @endif
            {{ $t('full_scan') }}
        </p>
    </main>

    @if ($siteKey)
        <script>
            // the search speaks the store's language, as whoever asked chose it
            // demo: its cart adds to no real store, and a product's choices come from the demo's own copy of them
            window.LetsSearchConfig = { site: @json($siteKey), api: @json(url('/api/v1')), locale: @json($site->choices['language'] ?? app()->getLocale()), launcher: 'float',
                demo: { options: @json(url('/demo/'.$site->token.'/options').'/') } };
        </script>
        <script src="{{ route('api.search.lets-search') }}" defer></script>
    @endif
</body>
</html>
