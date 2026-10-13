<!doctype html>
<html lang="{{ $rtl ? 'he' : 'en' }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $document->seoTitle() }} · {{ $shop->name }}</title>
    <meta name="description" content="{{ $document->metaDescription() }}">
    @foreach ($schemas as $schema)
        <script type="application/ld+json">{!! json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
    @endforeach
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Heebo:wght@300;400;500;700;900&display=swap" rel="stylesheet">
    <style>
        :root { --ink: #111; --paper: #fff; --rule: #e4e4e7; --soft: #f5f5f6; }
        * { box-sizing: border-box; }
        body { margin: 0; font: 17px/1.75 Heebo, system-ui, sans-serif; color: var(--ink); background: var(--paper); }
        header, main, footer { max-width: 760px; margin: 0 auto; padding: 0 20px; }
        header { padding-top: 28px; font-size: 14px; opacity: .7; }
        header a { color: inherit; text-decoration: none; font-weight: 500; }
        h1 { font-size: clamp(30px, 5vw, 44px); line-height: 1.1; font-weight: 900; margin: 18px 0 24px; letter-spacing: -0.01em; }
        .body h2 { font-size: 24px; font-weight: 700; margin: 36px 0 10px; }
        .body h3 { font-size: 20px; font-weight: 700; margin: 26px 0 8px; }
        .body p, .body li { font-weight: 400; }
        .body a { color: inherit; }
        .products { margin: 44px 0 0; padding-top: 24px; border-top: 1px solid var(--rule); }
        .faq { margin: 44px 0 0; padding-top: 24px; border-top: 1px solid var(--rule); }
        .faq h2 { font-size: 20px; font-weight: 700; margin: 0 0 6px; }
        .faq h3 { font-size: 17px; font-weight: 700; margin: 18px 0 4px; }
        .faq p { margin: 0; }
        .products h2 { font-size: 20px; font-weight: 700; margin: 0 0 14px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 16px; }
        .card { display: block; color: inherit; text-decoration: none; }
        .card img, .card .pic { display: block; width: 100%; aspect-ratio: 1; object-fit: cover; border-radius: 7px; background: var(--soft); }
        .card span { display: block; margin-top: 8px; font-size: 14px; line-height: 1.4; font-weight: 500; }
        footer { padding: 40px 20px 48px; font-size: 13px; opacity: .6; }
    </style>
</head>
<body>
<header>
    <a href="https://{{ $shop->domain }}">{{ $shop->name }}</a>
</header>
<main>
    <h1>{{ $post->title }}</h1>
    <div class="body">{!! $html !!}</div>
    @if ($products->isNotEmpty())
        <section class="products">
            <h2>{{ $rtl ? 'מהחנות' : 'From the store' }}</h2>
            <div class="grid">
                @foreach ($products as $product)
                    <a class="card" href="{{ $product->url ?? 'https://'.$shop->domain }}">
                        @if ($product->image_url)
                            <img src="{{ $product->image_url }}" alt="{{ $product->title }}" loading="lazy">
                        @else
                            <div class="pic"></div>
                        @endif
                        <span>{{ $product->title }}</span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif
    @if ($document->faq() !== [])
        <section class="faq">
            <h2>{{ $rtl ? 'שאלות נפוצות' : 'Frequently asked questions' }}</h2>
            @foreach ($document->faq() as $item)
                <h3>{{ $item['q'] }}</h3>
                <p>{{ $item['a'] }}</p>
            @endforeach
        </section>
    @endif
</main>
<footer>{{ $shop->name }}</footer>
</body>
</html>
